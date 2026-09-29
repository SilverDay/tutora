<?php

declare(strict_types=1);

namespace Tutora\Tests\Support;

use PDO;

final class Fixtures
{
    private static ?string $hash = null;

    public static function tenant(PDO $pdo, string $email): int
    {
        $pdo->prepare(
            'INSERT INTO tenants (email, display_name, password_hash, password_changed_at, created_at, updated_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([$email, 'Tutor ' . $email, self::$hash ??= password_hash('x', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]);
        return (int) $pdo->lastInsertId();
    }

    public static function workshop(PDO $pdo, int $tenantId, string $title = 'Workshop'): int
    {
        $pdo->prepare(
            'INSERT INTO workshops (tenant_id, title, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([$tenantId, $title]);
        return (int) $pdo->lastInsertId();
    }
}
