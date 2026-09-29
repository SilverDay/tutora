<?php

declare(strict_types=1);

namespace Tutora\Tenant;

use LogicException;
use PDO;
use PDOStatement;

/**
 * Thin data-access layer for tutor-side (tenant-owned) data.
 *
 * MariaDB has no row-level security, so tenant isolation is a code-correctness property.
 * This class makes the safe path the only path: it cannot be built without a TenantContext,
 * and every statement it runs MUST contain the named placeholder :tenant_id, which is always
 * bound from the context (never from caller-supplied parameters).
 */
final class TenantDb
{
    public function __construct(private readonly PDO $pdo, private readonly TenantContext $tenant)
    {
    }

    public function tenantId(): int
    {
        return $this->tenant->tenantId;
    }

    /** @param array<string,scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        if (!preg_match('/:tenant_id\b/', $sql)) {
            throw new LogicException('Tenant-scoped query must reference :tenant_id');
        }
        if (array_key_exists('tenant_id', $params) || array_key_exists(':tenant_id', $params)) {
            throw new LogicException('tenant_id must not be supplied by the caller');
        }
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . ltrim((string) $name, ':'), $value, self::pdoType($value));
        }
        $stmt->bindValue(':tenant_id', $this->tenant->tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<string,scalar|null> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,scalar|null> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Transaction on the underlying connection. The callback receives this TenantDb,
     * not the raw PDO, so tenant scoping is preserved inside the transaction.
     *
     * @template T
     * @param callable(TenantDb):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return \Tutora\Database\Transaction::run($this->pdo, fn () => $fn($this));
    }

    private static function pdoType(mixed $v): int
    {
        return match (true) {
            $v === null => PDO::PARAM_NULL,
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_BOOL,
            default => PDO::PARAM_STR,
        };
    }
}
