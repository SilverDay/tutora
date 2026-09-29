<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\HttpHarness;
use Tutora\Tests\Support\TestDatabase;

final class WhiteboardHttpTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private HttpHarness $tutor;
    private int $sid;
    /** @var list<int> */
    private array $blocks;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->tutor = new HttpHarness($this->pdo, $this->clock);
        $this->tutor->signUpTutor('tutor@example.org');
        $wid = (int) basename($this->tutor->post('/workshops', ['title' => 'Board'])->headers['Location']);
        $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'whiteboard', 'config' => '{"mode":"collaborative"}']);
        $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'poll', 'config' => '{"question":"q","options":["a","b"]}']);
        $this->sid = (int) basename($this->tutor->post("/workshops/{$wid}/sessions")->headers['Location']);
        $this->blocks = array_map('intval', $this->pdo->query("SELECT id FROM session_blocks WHERE session_id = {$this->sid} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));
        $this->tutor->get("/sessions/{$this->sid}");
    }

    private function png(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/page.png');
    }

    private function upload(HttpHarness $h, int $block, string $body, string $ct = 'image/png'): Response
    {
        return $h->app->handle(new Request('POST', "/api/tutor/sessions/{$this->sid}/blocks/{$block}/snapshots",
            headers: ['content-type' => $ct, 'x-csrf-token' => (string) $h->session->get('_csrf'), 'origin' => 'https://tutora.test'],
            body: $body, clientIp: $h->ip));
    }

    public function testTokensOverHttp(): void
    {
        $r = $this->tutor->app->handle(new Request('POST', "/api/tutor/sessions/{$this->sid}/blocks/{$this->blocks[0]}/whiteboard-token",
            headers: ['x-csrf-token' => (string) $this->tutor->session->get('_csrf'), 'origin' => 'https://tutora.test']));
        self::assertSame('tutor', HttpHarness::json($r)['role']);

        $p = new HttpHarness($this->pdo, $this->clock, '203.0.113.5');
        $code = (string) $this->pdo->query("SELECT join_code FROM sessions WHERE id = {$this->sid}")->fetchColumn();
        $cred = HttpHarness::json($p->api('POST', '/api/participant/join', ['code' => $code]))['credential'];
        $t = HttpHarness::json($p->api('POST', "/api/participant/sessions/{$this->sid}/blocks/{$this->blocks[0]}/whiteboard-token", bearer: $cred));
        self::assertSame('participant', $t['role']);
        self::assertSame(409, $p->api('POST', "/api/participant/sessions/{$this->sid}/blocks/{$this->blocks[1]}/whiteboard-token", bearer: $cred)->status);
        self::assertSame(401, $p->api('POST', "/api/participant/sessions/{$this->sid}/blocks/{$this->blocks[0]}/whiteboard-token")->status);
    }

    public function testSnapshotUploadValidationAndDelivery(): void
    {
        $r = $this->upload($this->tutor, $this->blocks[0], $this->png());
        self::assertSame(201, $r->status);
        $snap = HttpHarness::json($r)['id'];
        self::assertSame(422, $this->upload($this->tutor, $this->blocks[0], '<svg onload=alert(1)>')->status);
        self::assertSame(415, $this->upload($this->tutor, $this->blocks[0], $this->png(), 'text/html')->status);
        self::assertSame(404, $this->upload($this->tutor, $this->blocks[1], $this->png())->status, 'not a board block');

        $img = $this->tutor->get("/snapshots/{$snap}");
        self::assertSame(200, $img->status);
        self::assertSame('image/png', $img->headers['Content-Type']);
        self::assertStringContainsString('sandbox', $img->headers['Content-Security-Policy']);
        self::assertStringContainsString("/snapshots/{$snap}", $this->tutor->get("/sessions/{$this->sid}")->body);

        $other = new HttpHarness($this->pdo, $this->clock, '198.51.100.99');
        $other->signUpTutor('other@example.org');
        self::assertSame(404, $other->get("/snapshots/{$snap}")->status);
        self::assertSame(404, $this->upload($other, $this->blocks[0], $this->png())->status);

        // snapshot upload requires the CSRF header
        $noCsrf = $this->tutor->app->handle(new Request('POST', "/api/tutor/sessions/{$this->sid}/blocks/{$this->blocks[0]}/snapshots",
            headers: ['content-type' => 'image/png'], body: $this->png()));
        self::assertSame(403, $noCsrf->status);
    }

    public function testDeletingSessionRemovesSnapshotFiles(): void
    {
        $this->upload($this->tutor, $this->blocks[0], $this->png());
        $path = (string) $this->pdo->query('SELECT image_path FROM whiteboard_snapshots')->fetchColumn();
        self::assertFileExists($path);
        $this->tutor->post("/sessions/{$this->sid}/end");
        $this->tutor->get("/sessions/{$this->sid}");
        $this->tutor->post("/sessions/{$this->sid}/delete");
        self::assertFileDoesNotExist($path);
        self::assertContains(['drop_session', $this->sid], $this->tutor->whiteboard->calls);
    }

    public function testModerationReachesSidecar(): void
    {
        $actor = str_repeat('c', 32);
        $this->tutor->post("/sessions/{$this->sid}/moderation/remove-actor", ['actor' => $actor]);
        $this->tutor->get("/sessions/{$this->sid}");
        $this->tutor->post("/sessions/{$this->sid}/whiteboard/clear", ['block' => (string) $this->blocks[0]]);
        self::assertSame([['remove_actor', $this->sid, $actor], ['clear', $this->sid, $this->blocks[0]]], $this->tutor->whiteboard->calls);
        $this->tutor->get("/sessions/{$this->sid}");
        self::assertSame("/sessions/{$this->sid}?error=invalid", $this->tutor->post("/sessions/{$this->sid}/whiteboard/clear", ['block' => (string) $this->blocks[1]])->headers['Location']);
    }
}
