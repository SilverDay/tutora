<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Http\Request;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\HttpHarness;
use Tutora\Tests\Support\TestDatabase;

final class SessionExportHttpTest extends TestCase
{
    public function testExportIsPostOnlyAuditedAndTenantScoped(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $tutor = new HttpHarness($pdo, $clock);
        $tutor->signUpTutor('tutor@example.org');
        $wid = (int) basename($tutor->post('/workshops', ['title' => 'W'])->headers['Location']);
        $tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'poll', 'config' => '{"question":"Q","options":["A","B"]}']);
        $sid = (int) basename($tutor->post("/workshops/{$wid}/sessions")->headers['Location']);

        $r = $tutor->post("/sessions/{$sid}/export");
        self::assertSame(200, $r->status);
        self::assertSame('text/csv; charset=utf-8', $r->headers['Content-Type']);
        self::assertSame('attachment; filename="tutora-session-' . $sid . '.csv"', $r->headers['Content-Disposition']);
        self::assertSame('no-store', $r->headers['Cache-Control']);
        self::assertStringContainsString('"block_position","block_type"', $r->body);
        $details = json_decode((string) $pdo->query("SELECT details FROM audit_events WHERE event_type = 'session.exported'")->fetchColumn(), true);
        self::assertSame(['session_id' => $sid, 'rows' => 0], $details);

        self::assertNotSame(200, $tutor->get("/sessions/{$sid}/export")->status, 'no GET download (prefetch, history)');
        $noCsrf = $tutor->app->handle(new Request('POST', "/sessions/{$sid}/export", post: [], headers: ['origin' => 'https://tutora.test'], clientIp: $tutor->ip));
        self::assertSame(403, $noCsrf->status, 'CSRF required');

        $other = new HttpHarness($pdo, $clock, '198.51.100.99');
        $other->signUpTutor('other@example.org');
        self::assertSame(404, $other->post("/sessions/{$sid}/export")->status);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE event_type = 'session.exported'")->fetchColumn());
    }
}
