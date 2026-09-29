<?php

declare(strict_types=1);

namespace Tutora\Retention;

use Closure;
use PDO;
use Tutora\Security\Logger;
use Tutora\Session\SessionService;
use Tutora\Slides\SlideImportService;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Tenant\TenantContext;
use Tutora\Whiteboard\SnapshotService;

/**
 * Retention job (bin/purge.php, run by a systemd timer): deletes the data of ended sessions
 * whose expires_at (ended_at + tenant retention, default 30 days) has passed — DB rows
 * cascade in one statement, snapshot files, whiteboard sidecar documents and slide images
 * no longer used by any session go with them — plus expired pending signups and stale
 * rate-limit rows. Each session deletion is audited (reason "retention").
 *
 * Purged data can still exist in backups for the backup retention period (owner decision 5:
 * 14 days, i.e. at most 30 + 14 days after a session ended).
 */
final class RetentionPurger
{
    /** Rate-limit rows are only removed long after the longest window (24 h) ended. */
    private const RATE_LIMIT_KEEP_SECONDS = 7 * 86400;

    /**
     * @param Closure(TenantContext): array{SessionService, SnapshotService, SlideImportService} $servicesFor
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly Closure $servicesFor,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{sessions:int, failed:int, pending_signups:int, rate_limit_rows:int} */
    public function run(int $batch = 500): array
    {
        $now = Time::toDb($this->clock->now());
        $s = $this->pdo->prepare(
            "SELECT id, tenant_id FROM sessions WHERE status = 'ended' AND expires_at IS NOT NULL AND expires_at <= ? ORDER BY expires_at LIMIT " . max(1, $batch)
        );
        $s->execute([$now]);
        $done = 0;
        $failed = 0;
        $tenants = [];
        foreach ($s->fetchAll() as $row) {
            $sid = (int) $row['id'];
            $tenantId = (int) $row['tenant_id'];
            try {
                [$sessions, $snapshots] = ($this->servicesFor)(TenantContext::forSystemJob($tenantId));
                $snapshots->deleteSessionFiles($sid); // needs the session row, so first
                if ($sessions->delete($sid, null, 'retention')) {
                    $done++;
                    $tenants[$tenantId] = true;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('Retention purge of a session failed', ['session_id' => $sid, 'error' => $e::class]);
            }
        }
        foreach (array_keys($tenants) as $tenantId) {
            try {
                ($this->servicesFor)(TenantContext::forSystemJob($tenantId))[2]->collectOrphans();
            } catch (\Throwable $e) {
                $this->logger->error('Slide clean-up after retention purge failed', ['tenant_id' => $tenantId, 'error' => $e::class]);
            }
        }
        $pending = $this->pdo->prepare('DELETE FROM pending_signups WHERE expires_at < ?');
        $pending->execute([$now]);
        $rl = $this->pdo->prepare('DELETE FROM rate_limit_buckets WHERE window_start < ? AND (blocked_until IS NULL OR blocked_until < ?)');
        $rl->execute([Time::toDb($this->clock->now()->modify('-' . self::RATE_LIMIT_KEEP_SECONDS . ' seconds')), $now]);
        return ['sessions' => $done, 'failed' => $failed, 'pending_signups' => $pending->rowCount(), 'rate_limit_rows' => $rl->rowCount()];
    }
}
