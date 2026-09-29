<?php

declare(strict_types=1);

namespace Tutora\Security;

use PDO;
use Tutora\Database\Transaction;
use Tutora\Support\Clock;
use Tutora\Support\Time;

/**
 * Failure-based throttling with exponential backoff (no hard lockout).
 * After `freeAttempts` failures inside the window, each further failure blocks the key
 * for base * 2^(n - freeAttempts) seconds, capped at maxDelay. Keys are stored hashed.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {
    }

    /** Seconds the caller must wait before another attempt (0 = allowed). */
    public function retryAfter(string $key): int
    {
        $stmt = $this->pdo->prepare('SELECT blocked_until FROM rate_limit_buckets WHERE bucket_key = ?');
        $stmt->execute([self::hashKey($key)]);
        $until = $stmt->fetchColumn();
        if (!is_string($until)) {
            return 0;
        }
        $diff = Time::fromDb($until)->getTimestamp() - $this->clock->now()->getTimestamp();
        return max(0, $diff);
    }

    public function recordFailure(string $key, RateLimitPolicy $policy): void
    {
        $hash = self::hashKey($key);
        $now = $this->clock->now();
        Transaction::run($this->pdo, function (PDO $pdo) use ($hash, $now, $policy): void {
            $sel = $pdo->prepare('SELECT failures, window_start FROM rate_limit_buckets WHERE bucket_key = ? FOR UPDATE');
            $sel->execute([$hash]);
            $row = $sel->fetch();
            $failures = 1;
            $windowStart = $now;
            if ($row !== false && $now->getTimestamp() - Time::fromDb($row['window_start'])->getTimestamp() < $policy->windowSeconds) {
                $failures = (int) $row['failures'] + 1;
                $windowStart = Time::fromDb($row['window_start']);
            }
            $blockedUntil = null;
            if ($failures > $policy->freeAttempts) {
                $exp = min($failures - $policy->freeAttempts - 1, 30);
                $delay = min($policy->baseDelaySeconds * (2 ** $exp), $policy->maxDelaySeconds);
                $blockedUntil = Time::toDb($now->modify("+{$delay} seconds"));
            }
            $pdo->prepare(
                'INSERT INTO rate_limit_buckets (bucket_key, failures, window_start, blocked_until) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE failures = VALUES(failures), window_start = VALUES(window_start), blocked_until = VALUES(blocked_until)'
            )->execute([$hash, $failures, Time::toDb($windowStart), $blockedUntil]);
        });
    }

    /**
     * Counts an attempt (successful or not) against a fixed-window budget, e.g. join
     * attempts per IP. Returns false if the budget is exhausted.
     */
    public function consume(string $key, int $limit, int $windowSeconds): bool
    {
        $hash = self::hashKey($key);
        $now = $this->clock->now();
        return Transaction::run($this->pdo, function (PDO $pdo) use ($hash, $now, $limit, $windowSeconds): bool {
            $sel = $pdo->prepare('SELECT failures, window_start FROM rate_limit_buckets WHERE bucket_key = ? FOR UPDATE');
            $sel->execute([$hash]);
            $row = $sel->fetch();
            if ($row === false || $now->getTimestamp() - Time::fromDb($row['window_start'])->getTimestamp() >= $windowSeconds) {
                $count = 1;
                $start = $now;
            } else {
                $count = (int) $row['failures'] + 1;
                $start = Time::fromDb($row['window_start']);
            }
            $pdo->prepare(
                'INSERT INTO rate_limit_buckets (bucket_key, failures, window_start, blocked_until) VALUES (?, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE failures = VALUES(failures), window_start = VALUES(window_start)'
            )->execute([$hash, $count, Time::toDb($start)]);
            return $count <= $limit;
        });
    }

    public function reset(string $key): void
    {
        $this->pdo->prepare('DELETE FROM rate_limit_buckets WHERE bucket_key = ?')->execute([self::hashKey($key)]);
    }

    private static function hashKey(string $key): string
    {
        return hash('sha256', $key, true);
    }
}
