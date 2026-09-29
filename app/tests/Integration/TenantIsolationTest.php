<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use LogicException;
use PHPUnit\Framework\TestCase;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\TestDatabase;

/**
 * Cross-tenant read/write attempts must fail (spec: Auth & Multi-Tenancy mitigations).
 */
final class TenantIsolationTest extends TestCase
{
    public function testQueryWithoutTenantPlaceholderIsRefused(): void
    {
        $db = new TenantDb(TestDatabase::reset(), TenantContext::forAuthenticatedTutor(1));
        $this->expectException(LogicException::class);
        $db->all('SELECT * FROM workshops');
    }

    public function testCallerCannotSupplyTenantId(): void
    {
        $db = new TenantDb(TestDatabase::reset(), TenantContext::forAuthenticatedTutor(1));
        $this->expectException(LogicException::class);
        $db->all('SELECT * FROM workshops WHERE tenant_id = :tenant_id', ['tenant_id' => 2]);
    }

    public function testTenantScopedReadsAndWritesDoNotCrossTenants(): void
    {
        $pdo = TestDatabase::reset();
        $a = Fixtures::tenant($pdo, 'a@example.org');
        $b = Fixtures::tenant($pdo, 'b@example.org');
        $wa = Fixtures::workshop($pdo, $a, 'A');
        $wb = Fixtures::workshop($pdo, $b, 'B');

        $dbA = new TenantDb($pdo, TenantContext::forAuthenticatedTutor($a));

        // read of B's workshop by id through A's context
        self::assertNull($dbA->one('SELECT * FROM workshops WHERE id = :id AND tenant_id = :tenant_id', ['id' => $wb]));
        self::assertNotNull($dbA->one('SELECT * FROM workshops WHERE id = :id AND tenant_id = :tenant_id', ['id' => $wa]));

        // write to B's workshop through A's context affects nothing
        $n = $dbA->run('UPDATE workshops SET title = :t WHERE id = :id AND tenant_id = :tenant_id', ['t' => 'pwned', 'id' => $wb])->rowCount();
        self::assertSame(0, $n);
        self::assertSame('B', $pdo->query("SELECT title FROM workshops WHERE id = {$wb}")->fetchColumn());
    }

    public function testInvalidTenantIdRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantContext::forAuthenticatedTutor(0);
    }
}
