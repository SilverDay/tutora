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

    /**
     * Stores a new password hash. A change (not a rehash) also increments auth_epoch in the
     * same statement, ending all other sessions.
     *
     * @return int|null the new auth epoch for a change, null for a rehash
     */
    public function updatePasswordHash(int $id, string $hash, bool $isChange): ?int
    {
        $now = Time::toDb($this->clock->now());
        if (!$isChange) {
            $this->pdo->prepare('UPDATE tenants SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([$hash, $now, $id]);
            return null;
        }
        $this->pdo->prepare(
            'UPDATE tenants SET password_hash = ?, password_changed_at = ?, updated_at = ?, auth_epoch = LAST_INSERT_ID(auth_epoch + 1) WHERE id = ?'
        )->execute([$hash, $now, $now, $id]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Ends all sessions of the account (except one that adopts the returned value). */
    public function bumpAuthEpoch(int $id): int
    {
        // LAST_INSERT_ID(expr) returns exactly this statement's value on this connection (race-free)
        $this->pdo->prepare('UPDATE tenants SET auth_epoch = LAST_INSERT_ID(auth_epoch + 1), updated_at = ? WHERE id = ?')
            ->execute([Time::toDb($this->clock->now()), $id]);
        return (int) $this->pdo->lastInsertId();
    }

    public function authEpoch(int $id): ?int
    {
        $s = $this->pdo->prepare('SELECT auth_epoch FROM tenants WHERE id = ?');
        $s->execute([$id]);
        $v = $s->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    public function enableTotp(int $id, string $encryptedSecret, int $usedStep): void
    {
        $now = Time::toDb($this->clock->now());
        $this->pdo->prepare(
            'UPDATE tenants SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = ?, updated_at = ? WHERE id = ?'
        )->execute([$encryptedSecret, $now, $usedStep, $now, $id]);
    }

    /** Removes TOTP (admin reset) and ends all sessions: the next login goes through enrolment again. */
    public function resetTotp(int $id): void
    {
        $this->pdo->prepare('UPDATE tenants SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, auth_epoch = auth_epoch + 1, updated_at = ? WHERE id = ?')
            ->execute([Time::toDb($this->clock->now()), $id]);
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
