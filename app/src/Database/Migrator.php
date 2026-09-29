<?php

declare(strict_types=1);

namespace Tutora\Database;

use PDO;
use RuntimeException;

/**
 * Applies migrations/NNNN_*.sql in order, recording each in schema_migrations.
 * MariaDB DDL is not transactional, so a failed migration must be fixed forward.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    /** @return list<string> names of newly applied migrations */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME(3) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $applied = $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($applied);

        $files = glob(rtrim($this->directory, '/') . '/[0-9][0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);

        $new = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $stmt) {
                try {
                    $this->pdo->exec($stmt);
                } catch (\PDOException $e) {
                    throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
                }
            }
            $ins = $this->pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, UTC_TIMESTAMP(3))');
            $ins->execute([$name]);
            $new[] = $name;
        }
        return $new;
    }

    /**
     * Splits a migration file on ';' at end of line, ignoring '--' comment lines.
     * Migrations must not contain ';' inside string literals at line end.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $l) => !str_starts_with(ltrim($l), '--'),
        );
        $out = [];
        foreach (preg_split('/;\s*$/m', implode("\n", $lines)) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}
