<?php

declare(strict_types=1);

namespace Tutora\Activity;

use PDO;
use Tutora\Block\BlockConfig;
use Tutora\Block\BlockType;
use Tutora\Database\Transaction;
use Tutora\Participant\ParticipantContext;
use Tutora\Participant\ParticipantService;
use Tutora\Realtime\Broadcaster;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;

/**
 * Generic activity submissions (Poll, Meter, Rate, Rank, Word, Plot, Word Cloud, Write).
 *
 * One row per (session, block, participant), enforced by the UNIQUE key; resubmitting is an
 * intentional UPSERT (e.g. revising a Meter value). Only the session's current block accepts
 * input. After commit the anonymous aggregate is pushed to the room via the relay.
 */
final class SubmissionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly Broadcaster $broadcaster,
        private readonly ParticipantService $participants,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed> the stored (normalised) payload
     * @throws ValidationException
     * @throws BlockNotOpen
     */
    public function submit(ParticipantContext $p, int $sessionBlockId, array $payload): array
    {
        [$type, $stored] = Transaction::run($this->pdo, function (PDO $pdo) use ($p, $sessionBlockId, $payload): array {
            // shared lock on the session row: serialises with navigation (FOR UPDATE)
            $s = $pdo->prepare(
                "SELECT s.status, s.current_session_block_id, b.block_type, b.config_snapshot
                 FROM sessions s JOIN session_blocks b ON b.session_id = s.id
                 WHERE s.id = ? AND b.id = ? LOCK IN SHARE MODE"
            );
            $s->execute([$p->sessionId, $sessionBlockId]);
            $row = $s->fetch();
            if ($row === false || $row['status'] !== 'live' || (int) $row['current_session_block_id'] !== $sessionBlockId) {
                throw new BlockNotOpen();
            }
            $type = BlockType::from($row['block_type']);
            if (!$type->usesGenericSubmissions()) {
                throw new BlockNotOpen();
            }
            $config = json_decode((string) $row['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
            $normalized = SubmissionValidator::validate($type, $config, $payload);
            $now = Time::toDb($this->clock->now());
            $pdo->prepare(
                'INSERT INTO block_submissions (session_id, session_block_id, participant_id, moderation_actor_id, payload, submitted_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)'
            )->execute([$p->sessionId, $sessionBlockId, $p->participantId, $p->moderationActorIdBinary(),
                json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now, $now]);
            $this->participants->recordActivity($p);
            return [$type, $normalized];
        });
        $this->broadcastAggregate($p->sessionId, $sessionBlockId);
        return $stored;
    }

    /** @return array<string,mixed>|null the participant's own submission for a block */
    public function mine(ParticipantContext $p, int $sessionBlockId): ?array
    {
        $s = $this->pdo->prepare('SELECT payload FROM block_submissions WHERE session_id = ? AND session_block_id = ? AND participant_id = ?');
        $s->execute([$p->sessionId, $sessionBlockId, $p->participantId]);
        $v = $s->fetchColumn();
        return $v === false ? null : json_decode((string) $v, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Anonymous aggregate for a block (safe to show to every participant).
     *
     * @return array<string,mixed>|null
     */
    public function aggregate(int $sessionId, int $sessionBlockId): ?array
    {
        $s = $this->pdo->prepare('SELECT block_type, config_snapshot FROM session_blocks WHERE id = ? AND session_id = ?');
        $s->execute([$sessionBlockId, $sessionId]);
        $b = $s->fetch();
        if ($b === false || !BlockType::from($b['block_type'])->usesGenericSubmissions()) {
            return null;
        }
        $q = $this->pdo->prepare('SELECT payload FROM block_submissions WHERE session_id = ? AND session_block_id = ? ORDER BY id');
        $q->execute([$sessionId, $sessionBlockId]);
        $payloads = array_map(static fn ($v) => json_decode((string) $v, true, 64, JSON_THROW_ON_ERROR), $q->fetchAll(PDO::FETCH_COLUMN));
        return Aggregator::aggregate(BlockType::from($b['block_type']), json_decode((string) $b['config_snapshot'], true, 64, JSON_THROW_ON_ERROR), $payloads);
    }

    /**
     * Raw Write responses — tutor only (spec: never shown to other participants).
     *
     * @return list<array{actor:string, text:string, submitted_at:string}>
     */
    public function writeResponses(TenantDb $tenant, int $sessionId, int $sessionBlockId): array
    {
        $rows = $tenant->all(
            "SELECT HEX(bs.moderation_actor_id) AS actor, bs.payload, bs.updated_at
             FROM block_submissions bs JOIN sessions s ON s.id = bs.session_id
             JOIN session_blocks b ON b.id = bs.session_block_id AND b.session_id = bs.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND bs.session_block_id = :bid AND b.block_type = 'write'
             ORDER BY bs.id",
            ['sid' => $sessionId, 'bid' => $sessionBlockId],
        );
        return array_map(static fn ($r) => [
            'actor' => strtolower($r['actor']),
            'text' => json_decode((string) $r['payload'], true, 64, JSON_THROW_ON_ERROR)['text'],
            'submitted_at' => $r['updated_at'],
        ], $rows);
    }

    /**
     * Moderation: remove every submission of one participant in a session, identified only
     * by the opaque moderation actor id. Returns affected block ids.
     *
     * @return list<int>
     */
    public function removeActor(TenantDb $tenant, int $sessionId, string $actorHex): array
    {
        $bin = self::actorBin($actorHex);
        $blocks = array_map(static fn ($r) => (int) $r['session_block_id'], $tenant->all(
            'SELECT DISTINCT bs.session_block_id FROM block_submissions bs JOIN sessions s ON s.id = bs.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND bs.moderation_actor_id = :actor',
            ['sid' => $sessionId, 'actor' => $bin],
        ));
        $tenant->run(
            'DELETE bs FROM block_submissions bs JOIN sessions s ON s.id = bs.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND bs.moderation_actor_id = :actor',
            ['sid' => $sessionId, 'actor' => $bin],
        );
        foreach ($blocks as $b) {
            $this->broadcastAggregate($sessionId, $b);
        }
        return $blocks;
    }

    public function broadcastAggregate(int $sessionId, int $sessionBlockId): void
    {
        $agg = $this->aggregate($sessionId, $sessionBlockId);
        if ($agg !== null) {
            // hidden results go to the tutor only until revealed (owner decision 11)
            $this->broadcaster->broadcast(
                $sessionId,
                ['type' => 'activity_aggregate_update', 'session_block_id' => $sessionBlockId, 'aggregate' => $agg],
                $this->resultsHidden($sessionId, $sessionBlockId) ? Broadcaster::TARGET_TUTOR : null,
            );
        }
    }

    /** True while a block's results are set to "on reveal" and the tutor has not revealed them. */
    public function resultsHidden(int $sessionId, int $sessionBlockId): bool
    {
        $s = $this->pdo->prepare(
            'SELECT b.block_type, b.config_snapshot, r.session_block_id AS revealed
             FROM session_blocks b LEFT JOIN session_block_result_reveals r ON r.session_block_id = b.id AND r.session_id = b.session_id
             WHERE b.id = ? AND b.session_id = ?'
        );
        $s->execute([$sessionBlockId, $sessionId]);
        $b = $s->fetch();
        if ($b === false) {
            return false;
        }
        $config = json_decode((string) $b['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
        return BlockConfig::resultsOnReveal(BlockType::from($b['block_type']), $config) && $b['revealed'] === null;
    }

    /**
     * Tutor reveals a hidden block's results to participants (idempotent). Returns false if
     * the block is not in the tenant's session or is not set to "on reveal".
     */
    public function revealResults(TenantDb $tenant, int $sessionId, int $sessionBlockId): bool
    {
        $b = $tenant->one(
            'SELECT b.block_type, b.config_snapshot FROM session_blocks b JOIN sessions s ON s.id = b.session_id
             WHERE b.id = :bid AND s.id = :sid AND s.tenant_id = :tenant_id',
            ['bid' => $sessionBlockId, 'sid' => $sessionId],
        );
        if ($b === null || !BlockConfig::resultsOnReveal(BlockType::from((string) $b['block_type']), json_decode((string) $b['config_snapshot'], true, 64, JSON_THROW_ON_ERROR))) {
            return false;
        }
        $this->pdo->prepare('INSERT IGNORE INTO session_block_result_reveals (session_block_id, session_id, revealed_at) VALUES (?, ?, ?)')
            ->execute([$sessionBlockId, $sessionId, Time::toDb($this->clock->now())]);
        $this->broadcastAggregate($sessionId, $sessionBlockId);
        return true;
    }

    public static function actorBin(string $actorHex): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $actorHex) !== 1) {
            throw new ValidationException(['Invalid participant reference.']);
        }
        return (string) hex2bin($actorHex);
    }
}
