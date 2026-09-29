<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use Tutora\Database\Migrator;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\TestDatabase;

final class SchemaTest extends TestCase
{
    public function testMigrationsAreIdempotent(): void
    {
        $pdo = TestDatabase::pdo();
        self::assertSame([], (new Migrator($pdo, __DIR__ . '/../../migrations'))->migrate());
    }

    public function testSubmissionCannotReferenceBlockOfAnotherSession(): void
    {
        $pdo = TestDatabase::reset();
        $t = Fixtures::tenant($pdo, 'a@example.org');
        $pdo->exec("INSERT INTO sessions (tenant_id, workshop_title_snapshot, join_code, active_join_code, created_at)
                    VALUES ({$t},'w','AAAAAA','AAAAAA',UTC_TIMESTAMP(3)), ({$t},'w','BBBBBB','BBBBBB',UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO session_blocks (session_id, position, block_type, config_snapshot, config_version)
                    VALUES (1,0,'poll','{}',1), (2,0,'poll','{}',1)");
        $pdo->exec("INSERT INTO session_participants (session_id, moderation_actor_id, joined_at, last_activity_at,
                    presence_renewed_at, resume_token_hash, resume_token_expires_at)
                    VALUES (1, RANDOM_BYTES(16), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), RANDOM_BYTES(32), UTC_TIMESTAMP(3))");

        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO block_submissions (session_id, session_block_id, participant_id, moderation_actor_id, payload, submitted_at, updated_at)
                    VALUES (1, 2, 1, RANDOM_BYTES(16), '{}', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
    }

    public function testJoinCodeUniqueOnlyAmongLiveSessions(): void
    {
        $pdo = TestDatabase::reset();
        $t = Fixtures::tenant($pdo, 'a@example.org');
        // an ended session keeps join_code for history but releases active_join_code
        $pdo->exec("INSERT INTO sessions (tenant_id, workshop_title_snapshot, join_code, active_join_code, status, created_at)
                    VALUES ({$t},'w','AAAAAA',NULL,'ended',UTC_TIMESTAMP(3)), ({$t},'w','AAAAAA','AAAAAA','live',UTC_TIMESTAMP(3))");
        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO sessions (tenant_id, workshop_title_snapshot, join_code, active_join_code, created_at)
                    VALUES ({$t},'w','AAAAAA','AAAAAA',UTC_TIMESTAMP(3))");
    }

    public function testSessionSurvivesWorkshopDeletion(): void
    {
        $pdo = TestDatabase::reset();
        $t = Fixtures::tenant($pdo, 'a@example.org');
        $w = Fixtures::workshop($pdo, $t);
        $pdo->exec("INSERT INTO sessions (tenant_id, workshop_id, workshop_title_snapshot, join_code, created_at)
                    VALUES ({$t},{$w},'w','AAAAAA',UTC_TIMESTAMP(3))");
        $pdo->exec("DELETE FROM workshops WHERE id = {$w}");
        $row = $pdo->query('SELECT tenant_id, workshop_id FROM sessions')->fetch();
        self::assertSame($t, (int) $row['tenant_id']);
        self::assertNull($row['workshop_id']);
    }

    public function testSessionDeleteCascadesEvenWithCurrentBlockSet(): void
    {
        $pdo = TestDatabase::reset();
        $t = Fixtures::tenant($pdo, 'a@example.org');
        $pdo->exec("INSERT INTO sessions (tenant_id, workshop_title_snapshot, join_code, created_at) VALUES ({$t},'w','AAAAAA',UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO session_blocks (session_id, position, block_type, config_snapshot, config_version) VALUES (1,0,'poll','{}',1)");
        $pdo->exec('UPDATE sessions SET current_session_block_id = 1 WHERE id = 1');
        $pdo->exec('DELETE FROM sessions WHERE id = 1');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM session_blocks')->fetchColumn());
    }

    public function testMigrationSplitterIgnoresComments(): void
    {
        self::assertSame(['CREATE TABLE a (x INT)', 'SELECT 1'], Migrator::splitStatements("-- c;\nCREATE TABLE a (x INT);\n  -- y;\nSELECT 1;\n"));
    }
}
