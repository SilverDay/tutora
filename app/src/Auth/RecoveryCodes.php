<?php

declare(strict_types=1);

namespace Tutora\Auth;

use PDO;
use Tutora\Database\Transaction;
use Tutora\Support\Clock;
use Tutora\Support\Time;

/**
 * One-time MFA recovery codes (owner decision 3a): 10 codes of 16 base32 characters
 * (80 bits each), shown once, stored as SHA-256, consumed atomically.
 */
final class RecoveryCodes
{
    public const COUNT = 10;
    private const FORMAT = '/^[A-Z2-7]{4}-?[A-Z2-7]{4}-?[A-Z2-7]{4}-?[A-Z2-7]{4}$/';

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    public static function looksLikeCode(string $input): bool
    {
        return preg_match(self::FORMAT, strtoupper(str_replace(' ', '', trim($input)))) === 1;
    }

    /**
     * Replaces all codes of a tenant; returns the new plaintext codes (display once).
     *
     * @return list<string>
     */
    public function regenerate(int $tenantId): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = Base32::encode(random_bytes(10)); // 16 chars, 80 bits
            $codes[] = implode('-', str_split($raw, 4));
        }
        Transaction::run($this->pdo, function (PDO $pdo) use ($tenantId, $codes): void {
            $pdo->prepare('DELETE FROM tutor_recovery_codes WHERE tenant_id = ?')->execute([$tenantId]);
            $ins = $pdo->prepare('INSERT INTO tutor_recovery_codes (tenant_id, code_hash, created_at) VALUES (?, ?, ?)');
            foreach ($codes as $c) {
                $ins->execute([$tenantId, self::hash($c), Time::toDb($this->clock->now())]);
            }
        });
        return $codes;
    }

    /** Consumes a code once (atomic). */
    public function consume(int $tenantId, string $input): bool
    {
        if (!self::looksLikeCode($input)) {
            return false;
        }
        $s = $this->pdo->prepare('UPDATE tutor_recovery_codes SET used_at = ? WHERE tenant_id = ? AND code_hash = ? AND used_at IS NULL');
        $s->execute([Time::toDb($this->clock->now()), $tenantId, self::hash($input)]);
        return $s->rowCount() === 1;
    }

    public function remaining(int $tenantId): int
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM tutor_recovery_codes WHERE tenant_id = ? AND used_at IS NULL');
        $s->execute([$tenantId]);
        return (int) $s->fetchColumn();
    }

    public function deleteAll(int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM tutor_recovery_codes WHERE tenant_id = ?')->execute([$tenantId]);
    }

    private static function hash(string $code): string
    {
        return hash('sha256', str_replace(['-', ' '], '', strtoupper(trim($code))), true);
    }
}
