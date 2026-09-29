<?php

declare(strict_types=1);

namespace Tutora\Tests\Support;

use PDO;
use Tutora\Database\ConnectionFactory;
use Tutora\Database\Migrator;

/**
 * Connects to the MariaDB test database (TEST_DB_* env) and rebuilds the schema
 * once per PHPUnit process. Tests call reset() to truncate data between cases.
 */
final class TestDatabase
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $env = static fn (string $k, string $d) => getenv($k) !== false && getenv($k) !== '' ? (string) getenv($k) : $d;
            $name = $env('TEST_DB_NAME', 'tutora_test');
            if (!str_contains($name, 'test')) {
                throw new \RuntimeException('Refusing to run integration tests against a non-test database');
            }
            $pdo = ConnectionFactory::create(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('TEST_DB_HOST', '127.0.0.1'), $env('TEST_DB_PORT', '3306'), $name),
                $env('TEST_DB_USER', 'tutora'),
                $env('TEST_DB_PASSWORD', 'tutora_dev'),
            );
            self::dropAll($pdo);
            (new Migrator($pdo, __DIR__ . '/../../migrations'))->migrate();
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function reset(): PDO
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tables($pdo) as $t) {
            if ($t !== 'schema_migrations') {
                $pdo->exec("TRUNCATE TABLE `{$t}`");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $pdo;
    }

    private static function dropAll(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tables($pdo) as $t) {
            $pdo->exec("DROP TABLE `{$t}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    private static function tables(PDO $pdo): array
    {
        return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }
}
