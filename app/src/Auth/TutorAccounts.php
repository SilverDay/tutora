<?php

declare(strict_types=1);

namespace Tutora\Auth;

use PDO;
use Tutora\Support\Clock;
use Tutora\Support\Time;

/**
 * Account lookups for the authentication layer. This is the only place that reads the
 * tenants table without a TenantContext — it is what establishes the context.
 */
final class TutorAccounts
{
    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $normalizedEmail): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM tenants WHERE email = ?');
        $s->execute([$normalizedEmail]);
        $row = $s->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM tenants WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        return $row === false ? null : $row;
    }

    /** @return int|null new id, or null if the email is already registered */
    public function create(string $normalizedEmail, string $displayName, string $passwordHash): ?int
    {
        $now = Time::toDb($this->clock->now());
        try {
            $this->pdo->prepare(
                'INSERT INTO tenants (email, display_name, password_hash, password_changed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$normalizedEmail, $displayName, $passwordHash, $now, $now, $now]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return null;
            }
            throw $e;
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $id, string $hash, bool $isChange): void
    {
        $now = Time::toDb($this->clock->now());
        $sql = $isChange
            ? 'UPDATE tenants SET password_hash = ?, password_changed_at = ?, updated_at = ? WHERE id = ?'
            : 'UPDATE tenants SET password_hash = ?, updated_at = ? WHERE id = ?';
        $this->pdo->prepare($sql)->execute($isChange ? [$hash, $now, $now, $id] : [$hash, $now, $id]);
    }

    public function enableTotp(int $id, string $encryptedSecret, int $usedStep): void
    {
        $now = Time::toDb($this->clock->now());
        $this->pdo->prepare(
            'UPDATE tenants SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = ?, updated_at = ? WHERE id = ?'
        )->execute([$encryptedSecret, $now, $usedStep, $now, $id]);
    }

    /**
     * Atomically advances the last used TOTP step. Returns false if another request
     * already used this (or a later) step — closes the replay race between two requests.
     */
    public function consumeTotpStep(int $id, int $step): bool
    {
        $s = $this->pdo->prepare(
            'UPDATE tenants SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $s->execute([$step, $id, $step]);
        return $s->rowCount() === 1;
    }
}
