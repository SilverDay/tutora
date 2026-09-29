<?php

declare(strict_types=1);

namespace Tutora\Database;

use PDO;
use Throwable;

final class Transaction
{
    /**
     * Runs $fn inside a transaction; commits on success, rolls back on any throwable.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function run(PDO $pdo, callable $fn): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
