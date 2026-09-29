<?php

declare(strict_types=1);

namespace Tutora\Session;

use Tutora\Audit\AuditLog;
use Tutora\Block\BlockType;
use Tutora\Realtime\Broadcaster;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;
use Tutora\Whiteboard\NullWhiteboardModeration;
use Tutora\Whiteboard\WhiteboardModeration;

/**
 * Tutor-side live session lifecycle (tenant-scoped).
 *
 * Starting a session copies workshop_blocks into an immutable session_blocks snapshot in
 * one transaction; everything downstream references session_block ids only.
 */
final class SessionService
{
    private const JOIN_CODE_ATTEMPTS = 8;
    /** Participants may still resume for this long after the session ends (spec formula). */
    public const RESUME_GRACE_AFTER_END = 900;

    public function __construct(
        private readonly TenantDb $db,
        private readonly Clock $clock,
        private readonly Broadcaster $broadcaster,
        private readonly AuditLog $audit,
        private readonly int $defaultRetentionDays,
        private readonly WhiteboardModeration $whiteboard = new NullWhiteboardModeration(),
    ) {
    }

    /** @throws ValidationException */
    public function start(int $workshopId): ?int
    {
        for ($attempt = 0; $attempt < self::JOIN_CODE_ATTEMPTS; $attempt++) {
            try {
                return $this->db->transaction(fn (TenantDb $db) => $this->startTx($db, $workshopId, JoinCode::generate()));
            } catch (\PDOException $e) {
                // duplicate active join code: retry with a new one
                if (($e->errorInfo[1] ?? null) !== 1062 || !str_contains($e->getMessage(), 'uq_sessions_active_join_code')) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Could not allocate a unique join code');
    }

    private function startTx(TenantDb $db, int $workshopId, string $code): ?int
    {
        $workshop = $db->one('SELECT id, title FROM workshops WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE', ['id' => $workshopId]);
        if ($workshop === null) {
            return null;
        }
        $blocks = $db->all(
            'SELECT b.id, b.position, b.block_type, b.config, b.config_version, b.slide_asset_id
             FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id
             WHERE w.id = :wid AND w.tenant_id = :tenant_id ORDER BY b.position, b.id',
            ['wid' => $workshopId],
        );
        if ($blocks === []) {
            throw new ValidationException(['Add at least one block before starting a session.']);
        }
        $now = Time::toDb($this->clock->now());
        $db->run(
            "INSERT INTO sessions (tenant_id, workshop_id, workshop_title_snapshot, join_code, active_join_code, status, session_revision, started_at, created_at)
             VALUES (:tenant_id, :wid, :title, :code, :code2, 'live', 1, :now, :now2)",
            ['wid' => $workshopId, 'title' => $workshop['title'], 'code' => $code, 'code2' => $code, 'now' => $now, 'now2' => $now],
        );
        $sessionId = $db->lastInsertId();
        $first = null;
        foreach (array_values($blocks) as $pos => $b) {
            // INSERT ... SELECT through sessions keeps the write tenant-scoped
            $db->run(
                'INSERT INTO session_blocks (session_id, source_workshop_block_id, position, block_type, config_snapshot, config_version, slide_asset_id)
                 SELECT s.id, :src, :pos, :type, :config, :ver, :asset FROM sessions s WHERE s.id = :sid AND s.tenant_id = :tenant_id',
                ['src' => (int) $b['id'], 'pos' => $pos, 'type' => $b['block_type'], 'config' => $b['config'],
                 'ver' => (int) $b['config_version'], 'asset' => $b['slide_asset_id'] === null ? null : (int) $b['slide_asset_id'], 'sid' => $sessionId],
            );
            $first ??= $db->lastInsertId();
        }
        $db->run('UPDATE sessions SET current_session_block_id = :b WHERE id = :id AND tenant_id = :tenant_id', ['b' => $first, 'id' => $sessionId]);
        return $sessionId;
    }

    /** @return array<string,mixed>|null */
    public function find(int $sessionId): ?array
    {
        return $this->db->one('SELECT * FROM sessions WHERE id = :id AND tenant_id = :tenant_id', ['id' => $sessionId]);
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->db->all(
            'SELECT s.id, s.workshop_id, s.workshop_title_snapshot, s.join_code, s.status, s.started_at, s.ended_at,
                    (SELECT COUNT(*) FROM session_participants p WHERE p.session_id = s.id) AS participant_count
             FROM sessions s WHERE s.tenant_id = :tenant_id ORDER BY s.id DESC'
        );
    }

    /** @return list<array<string,mixed>> snapshot blocks with decoded config (tutor view, incl. answer keys) */
    public function blocks(int $sessionId): array
    {
        $rows = $this->db->all(
            'SELECT b.* FROM session_blocks b JOIN sessions s ON s.id = b.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY b.position',
            ['sid' => $sessionId],
        );
        foreach ($rows as &$r) {
            $r['config_snapshot'] = json_decode((string) $r['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
        }
        return $rows;
    }

    /**
     * Navigates the live session to one of its own snapshot blocks.
     *
     * @return int|null new session_revision, or null if session/block not found or not live
     */
    public function goToBlock(int $sessionId, int $sessionBlockId): ?int
    {
        $left = null;
        $rev = $this->db->transaction(function (TenantDb $db) use ($sessionId, $sessionBlockId, &$left): ?int {
            $s = $db->one("SELECT id, session_revision, current_session_block_id FROM sessions WHERE id = :id AND tenant_id = :tenant_id AND status = 'live' FOR UPDATE", ['id' => $sessionId]);
            if ($s === null) {
                return null;
            }
            if ($s['current_session_block_id'] !== null && (int) $s['current_session_block_id'] !== $sessionBlockId) {
                $prev = $db->one(
                    "SELECT b.id FROM session_blocks b JOIN sessions s ON s.id = b.session_id
                     WHERE b.id = :bid AND s.tenant_id = :tenant_id AND b.block_type IN ('whiteboard', 'annotate')",
                    ['bid' => (int) $s['current_session_block_id']],
                );
                $left = $prev === null ? null : (int) $prev['id'];
            }
            // the block must belong to this very session
            $b = $db->one(
                'SELECT b.id FROM session_blocks b JOIN sessions s ON s.id = b.session_id
                 WHERE b.id = :bid AND b.session_id = :sid AND s.tenant_id = :tenant_id',
                ['bid' => $sessionBlockId, 'sid' => $sessionId],
            );
            if ($b === null) {
                return null;
            }
            $db->run(
                'UPDATE sessions SET current_session_block_id = :bid, session_revision = session_revision + 1
                 WHERE id = :id AND tenant_id = :tenant_id',
                ['bid' => $sessionBlockId, 'id' => $sessionId],
            );
            return (int) $s['session_revision'] + 1;
        });
        if ($rev !== null) {
            if ($left !== null) {
                // spec: on block exit the tutor's client captures the rendered board as a snapshot
                $this->broadcaster->broadcast($sessionId, ['type' => 'capture', 'session_block_id' => $left], Broadcaster::TARGET_TUTOR);
            }
            $this->broadcaster->broadcast($sessionId, ['type' => 'block_change', 'session_revision' => $rev, 'session_block_id' => $sessionBlockId]);
        }
        return $rev;
    }

    /** Relative navigation helper (next/previous). */
    public function step(int $sessionId, int $delta): ?int
    {
        $s = $this->find($sessionId);
        if ($s === null || $s['current_session_block_id'] === null) {
            return null;
        }
        $blocks = $this->blocks($sessionId);
        $ids = array_map(static fn ($b) => (int) $b['id'], $blocks);
        $idx = array_search((int) $s['current_session_block_id'], $ids, true);
        $target = $ids[(int) $idx + $delta] ?? null;
        return $target === null ? null : $this->goToBlock($sessionId, $target);
    }

    public function end(int $sessionId): bool
    {
        $now = $this->clock->now();
        $rev = $this->db->transaction(function (TenantDb $db) use ($sessionId, $now): ?int {
            $s = $db->one(
                "SELECT s.id, s.session_revision, t.retention_days FROM sessions s JOIN tenants t ON t.id = s.tenant_id
                 WHERE s.id = :id AND s.tenant_id = :tenant_id AND s.status <> 'ended' FOR UPDATE",
                ['id' => $sessionId],
            );
            if ($s === null) {
                return null;
            }
            $days = $s['retention_days'] === null ? $this->defaultRetentionDays : (int) $s['retention_days'];
            $db->run(
                "UPDATE sessions SET status = 'ended', active_join_code = NULL, ended_at = :now, expires_at = :exp,
                        session_revision = session_revision + 1
                 WHERE id = :id AND tenant_id = :tenant_id",
                ['now' => Time::toDb($now), 'exp' => Time::toDb($now->modify("+{$days} days")), 'id' => $sessionId],
            );
            // resume window is capped at session_end + 15 min
            $db->run(
                'UPDATE session_participants p JOIN sessions s ON s.id = p.session_id
                 SET p.resume_token_expires_at = LEAST(p.resume_token_expires_at, :cap)
                 WHERE s.id = :sid AND s.tenant_id = :tenant_id',
                ['cap' => Time::toDb($now->modify('+' . self::RESUME_GRACE_AFTER_END . ' seconds')), 'sid' => $sessionId],
            );
            return (int) $s['session_revision'] + 1;
        });
        if ($rev !== null) {
            $this->broadcaster->broadcast($sessionId, ['type' => 'session_ended', 'session_revision' => $rev]);
            $this->whiteboard->endSession($sessionId);
        }
        return $rev !== null;
    }

    /** @param string $reason 'tutor' (manual) or 'retention' (purge job; no IP) */
    public function delete(int $sessionId, ?string $ip, string $reason = 'tutor'): bool
    {
        $ok = $this->db->run('DELETE FROM sessions WHERE id = :id AND tenant_id = :tenant_id', ['id' => $sessionId])->rowCount() === 1;
        if ($ok) {
            $this->audit->record($this->db->tenantId(), AuditLog::SESSION_DELETED, $ip, ['session_id' => $sessionId, 'reason' => $reason]);
            $this->whiteboard->dropSession($sessionId);
        }
        return $ok;
    }

    /**
     * Authoritative state for the tutor client (fetch-then-subscribe).
     *
     * @return array<string,mixed>|null
     */
    public function tutorState(int $sessionId): ?array
    {
        $s = $this->find($sessionId);
        if ($s === null) {
            return null;
        }
        $current = null;
        foreach ($this->blocks($sessionId) as $b) {
            if ((int) $b['id'] === (int) $s['current_session_block_id']) {
                $current = [
                    'id' => (int) $b['id'],
                    'position' => (int) $b['position'],
                    'type' => BlockType::from($b['block_type'])->value,
                    'config' => $b['config_snapshot'],
                    'slide_asset_id' => $b['slide_asset_id'] === null ? null : (int) $b['slide_asset_id'],
                ];
            }
        }
        return [
            'session_id' => (int) $s['id'],
            'status' => $s['status'],
            'join_code' => $s['status'] === 'ended' ? null : $s['join_code'],
            'session_revision' => (int) $s['session_revision'],
            'current_session_block_id' => $s['current_session_block_id'] === null ? null : (int) $s['current_session_block_id'],
            'current_block' => $current,
        ];
    }
}
