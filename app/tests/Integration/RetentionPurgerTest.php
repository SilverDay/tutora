<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Audit\AuditLog;
use Tutora\Block\BlockType;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Retention\RetentionPurger;
use Tutora\Security\Logger;
use Tutora\Security\RateLimiter;
use Tutora\Session\SessionService;
use Tutora\Slides\SlideImportService;
use Tutora\Slides\SlideStorage;
use Tutora\Support\FrozenClock;
use Tutora\Support\Time;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Whiteboard\NullWhiteboardModeration;
use Tutora\Whiteboard\SnapshotService;

final class RetentionPurgerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tutora-purge-test';
        SlideStorage::removeTree($this->root);
    }

    public function testPurgesExpiredSessionsWithFilesAndHousekeeping(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $bc = new NullBroadcaster();
        $wb = new NullWhiteboardModeration();
        $blocks = [[BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b']]]];
        $expired = new LiveSession($pdo, $clock, $bc, $blocks);
        $kept = new LiveSession($pdo, $clock, $bc, $blocks, 'second@example.org');
        $expired->join();
        foreach ([$expired, $kept] as $l) {
            $l->sessions->end($l->sessionId);
        }
        $tenant = (int) $pdo->query("SELECT tenant_id FROM sessions WHERE id = {$expired->sessionId}")->fetchColumn();

        // files owned by the expired session: a whiteboard snapshot and a slide image only it uses
        $snapDir = "{$this->root}/snapshots/{$tenant}/{$expired->sessionId}";
        mkdir($snapDir, 0770, true);
        file_put_contents("{$snapDir}/1.png", 'png');
        $pdo->exec("INSERT INTO slide_imports (tenant_id, workshop_id, original_filename, page_count, created_at) VALUES ({$tenant}, NULL, 'd.pdf', 1, NOW(3))");
        $imp = (int) $pdo->lastInsertId();
        $storage = new SlideStorage($this->root);
        $img = $storage->importDir($tenant, $imp) . '/1.png';
        file_put_contents($img, 'png');
        $pdo->exec("INSERT INTO slide_assets (slide_import_id, page_number, image_path, width, height) VALUES ({$imp}, 1, '{$img}', 10, 10)");
        $pdo->exec('UPDATE session_blocks SET slide_asset_id = ' . (int) $pdo->lastInsertId() . " WHERE session_id = {$expired->sessionId}");

        // expired vs. not yet expired
        $past = Time::toDb($clock->now()->modify('-1 minute'));
        $future = Time::toDb($clock->now()->modify('+1 day'));
        $pdo->exec("UPDATE sessions SET expires_at = '{$past}' WHERE id = {$expired->sessionId}");
        $pdo->exec("UPDATE sessions SET expires_at = '{$future}' WHERE id = {$kept->sessionId}");

        // housekeeping rows
        $pdo->exec("INSERT INTO pending_signups (email, display_name, password_hash, token_hash, expires_at, created_at)
                    VALUES ('old@example.org', 'x', 'h', UNHEX(SHA2('a',256)), '{$past}', NOW(3)), ('new@example.org', 'x', 'h', UNHEX(SHA2('b',256)), '{$future}', NOW(3))");
        $old = Time::toDb($clock->now()->modify('-8 days'));
        $recent = Time::toDb($clock->now()->modify('-1 hour'));
        $pdo->exec("INSERT INTO rate_limit_buckets (bucket_key, failures, window_start, blocked_until) VALUES
                    (UNHEX(SHA2('old',256)), 3, '{$old}', NULL), (UNHEX(SHA2('recent',256)), 3, '{$recent}', NULL),
                    (UNHEX(SHA2('blocked',256)), 9, '{$old}', '{$future}')");

        $purger = new RetentionPurger($pdo, $clock, fn (TenantContext $t): array => [
            new SessionService(new TenantDb($pdo, $t), $clock, $bc, new AuditLog($pdo, $clock), 30, $wb),
            new SnapshotService(new TenantDb($pdo, $t), $this->root, new RateLimiter($pdo, $clock), $clock),
            new SlideImportService(new TenantDb($pdo, $t), $storage, new RateLimiter($pdo, $clock), $clock, 1),
        ], new Logger(static fn () => null), new AuditLog($pdo, $clock));

        self::assertSame(['auto_ended' => 0, 'sessions' => 1, 'failed' => 0, 'pending_signups' => 1, 'rate_limit_rows' => 1], $purger->run());

        self::assertSame([$kept->sessionId], array_map('intval', $pdo->query('SELECT id FROM sessions')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM session_participants WHERE session_id = {$expired->sessionId}")->fetchColumn(), 'cascade');
        self::assertDirectoryDoesNotExist($snapDir);
        self::assertFileDoesNotExist($img, 'slide image used only by the purged session removed');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM slide_imports')->fetchColumn());
        self::assertContains(['drop_session', $expired->sessionId], $wb->calls, 'whiteboard documents dropped');
        $audit = json_decode((string) $pdo->query("SELECT details FROM audit_events WHERE event_type = 'session.deleted'")->fetchColumn(), true);
        self::assertSame(['session_id' => $expired->sessionId, 'reason' => 'retention'], $audit);
        self::assertSame(['new@example.org'], $pdo->query('SELECT email FROM pending_signups')->fetchAll(PDO::FETCH_COLUMN));
        $has = static fn (string $k): bool => (int) $pdo->query("SELECT COUNT(*) FROM rate_limit_buckets WHERE bucket_key = UNHEX(SHA2('{$k}',256))")->fetchColumn() === 1;
        self::assertFalse($has('old'), 'stale row removed');
        self::assertTrue($has('recent'), 'row within its window kept');
        self::assertTrue($has('blocked'), 'currently blocked row kept');

        self::assertSame(['auto_ended' => 0, 'sessions' => 0, 'failed' => 0, 'pending_signups' => 0, 'rate_limit_rows' => 0], $purger->run(), 'idempotent');
    }

    /** Owner decision: sessions still live 24 h after they started are ended automatically. */
    public function testEndsSessionsLiveForMoreThan24Hours(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $bc = new NullBroadcaster();
        $wb = new NullWhiteboardModeration();
        $blocks = [[BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b']]]];
        $overdue = new LiveSession($pdo, $clock, $bc, $blocks);
        $recent = new LiveSession($pdo, $clock, $bc, $blocks, 'second@example.org');
        $alreadyEnded = new LiveSession($pdo, $clock, $bc, $blocks, 'third@example.org');
        $alreadyEnded->sessions->end($alreadyEnded->sessionId);
        $pdo->exec("UPDATE sessions SET started_at = '" . Time::toDb($clock->now()->modify('-25 hours')) . "' WHERE id IN ({$overdue->sessionId}, {$alreadyEnded->sessionId})");
        $pdo->exec("UPDATE sessions SET started_at = '" . Time::toDb($clock->now()->modify('-23 hours')) . "' WHERE id = {$recent->sessionId}");
        $endedAtBefore = $pdo->query("SELECT ended_at FROM sessions WHERE id = {$alreadyEnded->sessionId}")->fetchColumn();
        $bc->sent = [];

        $purger = new RetentionPurger($pdo, $clock, fn (TenantContext $t): array => [
            new SessionService(new TenantDb($pdo, $t), $clock, $bc, new AuditLog($pdo, $clock), 30, $wb),
            new SnapshotService(new TenantDb($pdo, $t), $this->root, new RateLimiter($pdo, $clock), $clock),
            new SlideImportService(new TenantDb($pdo, $t), new SlideStorage($this->root), new RateLimiter($pdo, $clock), $clock, 1),
        ], new Logger(static fn () => null), new AuditLog($pdo, $clock), 24);

        self::assertSame(1, $purger->run()['auto_ended']);
        $row = $pdo->query("SELECT status, ended_at, expires_at, active_join_code FROM sessions WHERE id = {$overdue->sessionId}")->fetch();
        self::assertSame(['ended', '2026-03-01 10:00:00.000', '2026-03-31 10:00:00.000', null], [$row['status'], $row['ended_at'], $row['expires_at'], $row['active_join_code']], 'retention starts now');
        self::assertSame('live', $pdo->query("SELECT status FROM sessions WHERE id = {$recent->sessionId}")->fetchColumn(), '23 h: still live');
        self::assertSame($endedAtBefore, $pdo->query("SELECT ended_at FROM sessions WHERE id = {$alreadyEnded->sessionId}")->fetchColumn(), 'ended sessions untouched');
        self::assertSame([[$overdue->sessionId, 'session_ended']], array_map(static fn ($m) => [$m[0], $m[1]['type']], $bc->sent), 'participants told');
        self::assertContains(['end_session', $overdue->sessionId], $wb->calls);
        $audit = json_decode((string) $pdo->query("SELECT details FROM audit_events WHERE event_type = 'session.auto_ended'")->fetchColumn(), true);
        self::assertSame(['session_id' => $overdue->sessionId, 'max_live_hours' => 24], $audit);
        self::assertSame(0, $purger->run()['auto_ended'], 'idempotent');
    }
}
