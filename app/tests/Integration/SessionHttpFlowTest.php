<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\HttpHarness;
use Tutora\Tests\Support\TestDatabase;

final class SessionHttpFlowTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private HttpHarness $tutor;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->tutor = new HttpHarness($this->pdo, $this->clock);
        $this->tutor->signUpTutor('tutor@example.org');
    }

    /** @return array{int,int} workshop id, session id */
    private function startSession(): array
    {
        $r = $this->tutor->post('/workshops', ['title' => 'Threat modelling']);
        self::assertSame(303, $r->status);
        $wid = (int) basename($r->headers['Location']);
        self::assertSame(303, $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'poll', 'config' => '{"question":"Ready?","options":["Yes","No"]}'])->status);
        self::assertSame(303, $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'quiz', 'config' => '{"questions":[{"type":"true_false","prompt":"TLS 1.0 ok?","correct_answer":false}]}'])->status);
        $r = $this->tutor->post("/workshops/{$wid}/sessions");
        self::assertSame(303, $r->status);
        return [$wid, (int) basename($r->headers['Location'])];
    }

    private function joinCode(int $sid): string
    {
        return (string) $this->pdo->query("SELECT join_code FROM sessions WHERE id = {$sid}")->fetchColumn();
    }

    public function testTutorToParticipantEndToEnd(): void
    {
        [, $sid] = $this->startSession();
        self::assertStringContainsString($this->joinCode($sid), $this->tutor->get("/sessions/{$sid}")->body);

        $p = new HttpHarness($this->pdo, $this->clock, '203.0.113.20');
        $r = $p->api('POST', '/api/participant/join', ['code' => $this->joinCode($sid), 'display_name' => 'Bea']);
        self::assertSame(201, $r->status);
        $joined = HttpHarness::json($r);

        $state = HttpHarness::json($p->api('GET', "/api/participant/sessions/{$sid}/state", bearer: $joined['credential']));
        self::assertSame('poll', $state['current_block']['type']);
        self::assertSame(1, $state['session_revision']);

        self::assertSame(303, $this->tutor->post("/sessions/{$sid}/navigate", ['direction' => 'next'])->status);
        $state = HttpHarness::json($p->api('GET', "/api/participant/sessions/{$sid}/state", bearer: $joined['credential']));
        self::assertSame('quiz', $state['current_block']['type']);
        self::assertSame(2, $state['session_revision']);
        self::assertArrayNotHasKey('correct_answer', $state['current_block']['config']['questions'][0]);

        $tok = HttpHarness::json($p->api('POST', "/api/participant/sessions/{$sid}/connection-token", bearer: $joined['credential']));
        self::assertSame(60, $tok['expires_in']);

        $pres = HttpHarness::json($p->api('POST', "/api/participant/sessions/{$sid}/presence", bearer: $joined['credential']));
        self::assertNotEmpty($pres['credential']);

        $resumed = $p->api('POST', '/api/participant/resume', ['resume_token' => $joined['resume_token']]);
        self::assertSame(200, $resumed->status);

        self::assertSame(303, $this->tutor->post("/sessions/{$sid}/end")->status);
        self::assertSame(401, $p->api('GET', "/api/participant/sessions/{$sid}/state", bearer: $joined['credential'])->status);
    }

    public function testParticipantEndpointsRequireMatchingCredential(): void
    {
        [, $sid] = $this->startSession();
        [, $sid2] = $this->startSession();
        $p = new HttpHarness($this->pdo, $this->clock, '203.0.113.21');
        $j = HttpHarness::json($p->api('POST', '/api/participant/join', ['code' => $this->joinCode($sid)]));

        self::assertSame(401, $p->api('GET', "/api/participant/sessions/{$sid}/state")->status);
        self::assertSame(401, $p->api('GET', "/api/participant/sessions/{$sid2}/state", bearer: $j['credential'])->status);
        // the tutor's cookie session does not authorise participant endpoints
        self::assertSame(401, $this->tutor->api('GET', "/api/participant/sessions/{$sid}/state")->status);
    }

    public function testJoinRequiresJsonContentType(): void
    {
        $p = new HttpHarness($this->pdo, $this->clock, '203.0.113.22');
        $r = $p->app->handle(new \Tutora\Http\Request('POST', '/api/participant/join', post: ['code' => 'ABCDEF'], headers: ['content-type' => 'application/x-www-form-urlencoded']));
        self::assertSame(415, $r->status);
    }

    public function testOtherTutorCannotAccessWorkshopOrSession(): void
    {
        [$wid, $sid] = $this->startSession();
        $other = new HttpHarness($this->pdo, $this->clock, '198.51.100.99');
        $other->signUpTutor('other@example.org');

        self::assertSame(404, $other->get("/workshops/{$wid}")->status);
        self::assertSame(404, $other->get("/sessions/{$sid}")->status);
        self::assertSame(404, $other->get("/api/tutor/sessions/{$sid}/state")->status);
        self::assertSame(404, $other->post("/workshops/{$wid}/delete")->status);
        self::assertSame(404, $other->post("/sessions/{$sid}/end")->status);
        self::assertSame(404, $other->post("/sessions/{$sid}/delete")->status);
        self::assertSame(404, $other->post("/workshops/{$wid}/sessions")->status);
        $blockId = (int) $this->pdo->query("SELECT id FROM workshop_blocks WHERE workshop_id = {$wid} LIMIT 1")->fetchColumn();
        self::assertSame(404, $other->post("/blocks/{$blockId}", ['config' => '{}'])->status);
        self::assertSame(404, $other->post("/blocks/{$blockId}/delete")->status);
        self::assertStringNotContainsString('Threat modelling', $other->get('/dashboard')->body);

        self::assertSame('live', $this->pdo->query("SELECT status FROM sessions WHERE id = {$sid}")->fetchColumn());
    }

    public function testTutorApiRequiresLogin(): void
    {
        [, $sid] = $this->startSession();
        $anon = new HttpHarness($this->pdo, $this->clock, '198.51.100.50');
        self::assertSame(401, $anon->get("/api/tutor/sessions/{$sid}/state")->status);
        self::assertSame('/login', $anon->get("/sessions/{$sid}")->headers['Location']);
    }

    public function testInvalidBlockConfigShowsErrorsAndIsEncoded(): void
    {
        $r = $this->tutor->post('/workshops', ['title' => '<img src=x onerror=alert(1)>']);
        $wid = (int) basename($r->headers['Location']);
        $page = $this->tutor->get("/workshops/{$wid}");
        self::assertStringNotContainsString('<img src=x', $page->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $page->body);

        $r = $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'poll', 'config' => '{"question":"q","options":["only"]}']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('config.options must be a list of 2', $r->body);
        $r = $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'write', 'config' => 'not json']);
        self::assertSame(422, $r->status);
    }

    public function testWorkshopDeletionAudited(): void
    {
        [$wid] = $this->startSession();
        self::assertSame(303, $this->tutor->post("/workshops/{$wid}/delete")->status);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE event_type = 'workshop.deleted'")->fetchColumn());
    }
}
