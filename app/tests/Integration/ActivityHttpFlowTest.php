<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\HttpHarness;
use Tutora\Tests\Support\TestDatabase;

final class ActivityHttpFlowTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private HttpHarness $tutor;
    private int $sid;
    /** @var list<int> */
    private array $blocks;
    private int $ip = 1;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->tutor = new HttpHarness($this->pdo, $this->clock);
        $this->tutor->signUpTutor('tutor@example.org');
        $wid = (int) basename($this->tutor->post('/workshops', ['title' => 'Activities'])->headers['Location']);
        foreach ([
            ['poll', '{"question":"Ready?","options":["Yes","No"]}'],
            ['write', '{"prompt":"Reflect"}'],
            ['wall', '{"columns":["Good","Improve"]}'],
            ['quiz', '{"pacing":"tutor","questions":[{"id":"q1","type":"true_false","prompt":"TLS 1.0 ok?","correct_answer":false}]}'],
        ] as [$type, $config]) {
            self::assertSame(303, $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => $type, 'config' => $config])->status);
        }
        $this->sid = (int) basename($this->tutor->post("/workshops/{$wid}/sessions")->headers['Location']);
        $this->blocks = array_map('intval', $this->pdo->query("SELECT id FROM session_blocks WHERE session_id = {$this->sid} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{HttpHarness,string} */
    private function participant(): array
    {
        $h = new HttpHarness($this->pdo, $this->clock, '203.0.113.' . $this->ip++);
        $code = (string) $this->pdo->query("SELECT join_code FROM sessions WHERE id = {$this->sid}")->fetchColumn();
        return [$h, HttpHarness::json($h->api('POST', '/api/participant/join', ['code' => $code]))['credential']];
    }

    private function goTo(int $i): void
    {
        $this->tutor->get("/sessions/{$this->sid}");
        self::assertSame(303, $this->tutor->post("/sessions/{$this->sid}/navigate", ['session_block_id' => (string) $this->blocks[$i]])->status);
    }

    private function url(string $suffix, int $block): string
    {
        return "/api/participant/sessions/{$this->sid}/blocks/{$block}{$suffix}";
    }

    public function testPollSubmitAndStateAggregate(): void
    {
        [$p, $cred] = $this->participant();
        $r = $p->api('POST', $this->url('/submission', $this->blocks[0]), ['payload' => ['selected' => ['o1']]], $cred);
        self::assertSame(200, $r->status);
        $state = HttpHarness::json($p->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $cred));
        self::assertSame(['selected' => ['o1']], $state['current_block']['state']['mine']);
        self::assertSame(1, $state['current_block']['state']['aggregate']['options'][0]['count']);

        self::assertSame(422, $p->api('POST', $this->url('/submission', $this->blocks[0]), ['payload' => ['selected' => ['zz']]], $cred)->status);
        self::assertSame(409, $p->api('POST', $this->url('/submission', $this->blocks[1]), ['payload' => ['text' => 'x']], $cred)->status, 'not the current block');
        self::assertSame(401, $p->api('POST', $this->url('/submission', $this->blocks[0]), ['payload' => ['selected' => ['o1']]])->status);
    }

    public function testWriteResponsesOnlyInTutorState(): void
    {
        $this->goTo(1);
        [$p, $cred] = $this->participant();
        $p->api('POST', $this->url('/submission', $this->blocks[1]), ['payload' => ['text' => 'secret <b>reflection</b>']], $cred);

        $pState = $p->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $cred)->body;
        [$other, $otherCred] = $this->participant();
        $otherState = $other->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $otherCred)->body;
        self::assertStringNotContainsString('secret', $otherState, 'other participants never see Write text');
        self::assertStringContainsString('"responses":1', $otherState);

        $tState = HttpHarness::json($this->tutor->get("/api/tutor/sessions/{$this->sid}/state"));
        self::assertSame('secret <b>reflection</b>', $tState['current_block']['state']['responses'][0]['text']);
        $page = $this->tutor->get("/sessions/{$this->sid}")->body;
        self::assertStringNotContainsString('<b>reflection</b>', $page, 'tutor console output-encodes responses');
        unset($pState);
    }

    public function testWallFlowAndModeration(): void
    {
        $this->goTo(2);
        [$p, $cred] = $this->participant();
        [$q, $qcred] = $this->participant();
        $card = HttpHarness::json($p->api('POST', $this->url('/wall/cards', $this->blocks[2]), ['text' => 'Great', 'column_id' => 'c1'], $cred))['id'];
        $q->api('POST', $this->url('/wall/cards', $this->blocks[2]), ['text' => 'Spam', 'column_id' => 'c2'], $qcred);

        self::assertSame(404, $q->api('POST', $this->url("/wall/cards/{$card}/delete", $this->blocks[2]), [], $qcred)->status, 'not own card');
        self::assertSame(200, $p->api('POST', $this->url("/wall/cards/{$card}/move", $this->blocks[2]), ['column_id' => 'c2', 'position' => 0], $cred)->status);

        $tState = HttpHarness::json($this->tutor->get("/api/tutor/sessions/{$this->sid}/state"));
        $spam = array_values(array_filter($tState['current_block']['state']['cards'], static fn ($c) => $c['text'] === 'Spam'))[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $spam['actor']);

        $this->tutor->get("/sessions/{$this->sid}");
        self::assertSame(303, $this->tutor->post("/sessions/{$this->sid}/moderation/remove-actor", ['actor' => $spam['actor']])->status);
        $texts = array_column(HttpHarness::json($p->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $cred))['current_block']['state']['cards'], 'text');
        self::assertSame(['Great'], $texts);
        self::assertArrayNotHasKey('actor', HttpHarness::json($p->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $cred))['current_block']['state']['cards'][0]);
    }

    public function testQuizThroughHttp(): void
    {
        $this->goTo(3);
        [$p, $cred] = $this->participant();
        $quizBlock = $this->blocks[3];
        self::assertSame(409, $p->api('POST', $this->url('/quiz/q1/answer', $quizBlock), ['answer' => false], $cred)->status, 'pending');

        $this->tutor->get("/sessions/{$this->sid}");
        self::assertSame("/sessions/{$this->sid}", $this->tutor->post("/sessions/{$this->sid}/quiz/start", ['block' => (string) $quizBlock, 'question' => 'q1'])->headers['Location']);
        $state = $p->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $cred)->body;
        self::assertStringNotContainsString('correct_answer', $state);

        self::assertSame(201, $p->api('POST', $this->url('/quiz/q1/answer', $quizBlock), ['answer' => false], $cred)->status);
        self::assertSame(409, $p->api('POST', $this->url('/quiz/q1/answer', $quizBlock), ['answer' => true], $cred)->status);

        $this->tutor->get("/sessions/{$this->sid}");
        // starting again reports an error via a fixed message key, not reflected input
        $r = $this->tutor->post("/sessions/{$this->sid}/quiz/start", ['block' => (string) $quizBlock, 'question' => 'q1']);
        self::assertSame("/sessions/{$this->sid}?error=quiz_open", $r->headers['Location']);

        $this->tutor->post("/sessions/{$this->sid}/quiz/reveal", ['block' => (string) $quizBlock, 'question' => 'q1']);
        $q = HttpHarness::json($p->api('GET', "/api/participant/sessions/{$this->sid}/state", bearer: $cred))['current_block']['state']['quiz']['questions'][0];
        self::assertSame('REVEALED', $q['status']);
        self::assertTrue($q['my_correct']);
        self::assertFalse($q['correct_answer']);
    }

    public function testErrorQueryParamIsNotReflected(): void
    {
        $page = $this->tutor->get("/sessions/{$this->sid}?error=%3Cscript%3Ealert(1)%3C/script%3E")->body;
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringNotContainsString('alert(1)', $page);
    }

    public function testOtherTutorCannotUseActions(): void
    {
        $other = new HttpHarness($this->pdo, $this->clock, '198.51.100.99');
        $other->signUpTutor('other@example.org');
        foreach ([
            ["/sessions/{$this->sid}/quiz/start", ['block' => (string) $this->blocks[3], 'question' => 'q1']],
            ["/sessions/{$this->sid}/quiz/reveal", ['block' => (string) $this->blocks[3], 'question' => 'q1']],
            ["/sessions/{$this->sid}/wall/cards", ['block' => (string) $this->blocks[2], 'text' => 'x', 'column_id' => 'c1']],
            ["/sessions/{$this->sid}/moderation/remove-actor", ['actor' => str_repeat('a', 32)]],
        ] as [$path, $fields]) {
            self::assertSame(404, $other->post($path, $fields)->status, $path);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM quiz_question_runs')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM wall_cards')->fetchColumn());
    }

    public function testParticipantCannotActOnAnotherSession(): void
    {
        [$p, $cred] = $this->participant();
        $wid = (int) basename($this->tutor->post('/workshops', ['title' => 'Second'])->headers['Location']);
        $this->tutor->post("/workshops/{$wid}/blocks", ['block_type' => 'poll', 'config' => '{"question":"q","options":["a","b"]}']);
        $sid2 = (int) basename($this->tutor->post("/workshops/{$wid}/sessions")->headers['Location']);
        $b2 = (int) $this->pdo->query("SELECT id FROM session_blocks WHERE session_id = {$sid2}")->fetchColumn();
        // credential for session 1, path for session 2
        self::assertSame(401, $p->api('POST', "/api/participant/sessions/{$sid2}/blocks/{$b2}/submission", ['payload' => ['selected' => ['o1']]], $cred)->status);
        // own session in path, other session's block id
        self::assertSame(409, $p->api('POST', "/api/participant/sessions/{$this->sid}/blocks/{$b2}/submission", ['payload' => ['selected' => ['o1']]], $cred)->status);
    }
}
