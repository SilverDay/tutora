<?php

declare(strict_types=1);

namespace Tutora\Whiteboard;

use Tutora\Security\RateLimiter;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;

/**
 * Whiteboard export snapshots, captured client-side by the tutor's browser (spec: the
 * sidecar never renders). Tenant-scoped; files live outside the web root.
 */
final class SnapshotService
{
    public const MAX_BYTES = 5 * 1048576;
    public const MAX_SIDE = 4096;
    public const PER_SESSION_PER_HOUR = 120;

    public function __construct(
        private readonly TenantDb $db,
        private readonly string $storageRoot,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
    ) {
    }

    /** @throws ValidationException */
    public function store(int $sessionId, int $blockId, string $png): ?int
    {
        $block = $this->db->one(
            "SELECT b.id FROM session_blocks b JOIN sessions s ON s.id = b.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND b.id = :bid AND b.block_type IN ('whiteboard', 'annotate')",
            ['sid' => $sessionId, 'bid' => $blockId],
        );
        if ($block === null) {
            return null;
        }
        if (strlen($png) > self::MAX_BYTES || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new ValidationException(['Snapshot must be a PNG image up to 5 MB.']);
        }
        $info = @getimagesizefromstring($png);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new ValidationException(['Snapshot must be a PNG image up to 4096 px per side.']);
        }
        if (!$this->limiter->consume('wb-snapshot:' . $sessionId, self::PER_SESSION_PER_HOUR, 3600)) {
            throw new ValidationException(['Too many snapshots. Please try again later.']);
        }
        $dir = $this->dir($sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create snapshot directory');
        }
        $path = $dir . '/' . $blockId . '-' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($path, $png);
        chmod($path, 0640);
        try {
            $this->db->run(
                'INSERT INTO whiteboard_snapshots (session_id, session_block_id, image_path, captured_at)
                 SELECT s.id, :bid, :path, :now FROM sessions s WHERE s.id = :sid AND s.tenant_id = :tenant_id',
                ['bid' => $blockId, 'path' => $path, 'now' => Time::toDb($this->clock->now()), 'sid' => $sessionId],
            );
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        return $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function list(int $sessionId): array
    {
        return $this->db->all(
            'SELECT w.id, w.session_block_id, w.captured_at FROM whiteboard_snapshots w JOIN sessions s ON s.id = w.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY w.captured_at, w.id',
            ['sid' => $sessionId],
        );
    }

    public function path(int $snapshotId): ?string
    {
        $row = $this->db->one(
            'SELECT w.image_path FROM whiteboard_snapshots w JOIN sessions s ON s.id = w.session_id
             WHERE w.id = :id AND s.tenant_id = :tenant_id',
            ['id' => $snapshotId],
        );
        if ($row === null) {
            return null;
        }
        $real = realpath((string) $row['image_path']);
        $root = realpath($this->storageRoot . '/snapshots');
        return $real !== false && $root !== false && str_starts_with($real, $root . '/') ? $real : null;
    }

    /** Removes the snapshot files of a session (before the session is deleted). */
    public function deleteSessionFiles(int $sessionId): void
    {
        if ($this->db->one('SELECT id FROM sessions WHERE id = :id AND tenant_id = :tenant_id', ['id' => $sessionId]) !== null) {
            \Tutora\Slides\SlideStorage::removeTree($this->dir($sessionId));
        }
    }

    private function dir(int $sessionId): string
    {
        return $this->storageRoot . '/snapshots/' . $this->db->tenantId() . '/' . $sessionId;
    }
}
