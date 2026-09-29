<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Activity\BlockNotOpen;
use Tutora\Audit\AuditLog;
use Tutora\Block\BlockType;
use Tutora\Http\Request;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Security\HmacToken;
use Tutora\Session\SessionService;
use Tutora\Support\FrozenClock;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\HttpHarness;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Whiteboard\NullWhiteboardModeration;
use Tutora\Whiteboard\WhiteboardService;

final class WhiteboardTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private NullBroadcaster $bc;
    private LiveSession $live;
    private HmacToken $tokens;
    private WhiteboardService $wb;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->bc = new NullBroadcaster();
        $this->live = new LiveSession($this->pdo, $this->clock, $this->bc, [
            [BlockType::Whiteboard, ['mode' => 'collaborative']],
            [BlockType::Annotate, ['prompt' => 'Label', 'tags' => ['Risk', ['id' => 'asset', 'label' => 'Asset']]]],
            [BlockType::Whiteboard, ['mode' => 'presenter']],
            [BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b']]],
        ]);
        $this->tokens = new HmacToken(str_repeat('w', 32), HmacToken::AUD_WHITEBOARD, $this->clock);
        $this->wb = new WhiteboardService($this->pdo, $this->tokens);
    }

    public function testParticipantTokenClaims(): void
    {
        $p = $this->live->join();
        $t = $this->wb->participantToken($p, $this->live->blocks[0]);
        $c = $this->tokens->verify($t['token']);
        self::assertSame(['sid' => $this->live->sessionId, 'bid' => $this->live->blocks[0], 'actor' => $p->actorId, 'role' => 'participant',
            'kind' => 'whiteboard', 'tags' => [], 'aud' => 'tutora-whiteboard', 'exp' => $this->clock->now()->getTimestamp() + 60], $c);

        $this->live->goTo(1);
        $c = $this->tokens->verify($this->wb->participantToken($p, $this->live->blocks[1])['token']);
        self::assertSame('annotate', $c['kind']);
        self::assertSame(['t1', 'asset'], $c['tags']);

        $this->live->goTo(2);
        self::assertNull($this->wb->participantToken($p, $this->live->blocks[2]), 'presenter mode uses the relay only');
    }

    public function testParticipantTokenOnlyForCurrentBoardBlock(): void
    {
        $p = $this->live->join();
        try {
            $this->wb->participantToken($p, $this->live->blocks[1]);
            self::fail('token for a non-current block');
        } catch (BlockNotOpen) {
        }
        $this->live->goTo(3);
        $this->expectException(BlockNotOpen::class);
        $this->wb->participantToken($p, $this->live->blocks[3]);
    }

    public function testTutorTokenTenantScoped(): void
    {
        $t = $this->wb->tutorToken($this->live->tenantDb, $this->live->sessionId, $this->live->blocks[1]);
        self::assertSame('tutor', $this->tokens->verify($t['token'])['role']);
        $other = new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($this->pdo, 'x@example.org')));
        self::assertNull($this->wb->tutorToken($other, $this->live->sessionId, $this->live->blocks[1]));
        self::assertNull($this->wb->tutorToken($this->live->tenantDb, $this->live->sessionId, $this->live->blocks[3]), 'not a board');
    }

    public function testCaptureBroadcastOnLeavingBoardAndSidecarLifecycle(): void
    {
        $mod = new NullWhiteboardModeration();
        $svc = new SessionService($this->live->tenantDb, $this->clock, $this->bc, new AuditLog($this->pdo, $this->clock), 30, $mod);
        $svc->goToBlock($this->live->sessionId, $this->live->blocks[3]); // leaving block 0 (whiteboard)
        $capture = array_values(array_filter($this->bc->sent, static fn ($m) => $m[1]['type'] === 'capture'));
        self::assertSame([[$this->live->sessionId, ['type' => 'capture', 'session_block_id' => $this->live->blocks[0]], 'tutor']], $capture);

        $this->bc->sent = [];
        $svc->goToBlock($this->live->sessionId, $this->live->blocks[0]); // leaving a poll: no capture
        self::assertSame([], array_filter($this->bc->sent, static fn ($m) => $m[1]['type'] === 'capture'));

        $svc->end($this->live->sessionId);
        $svc->delete($this->live->sessionId, '198.51.100.1');
        self::assertSame([['end_session', $this->live->sessionId], ['drop_session', $this->live->sessionId]], $mod->calls);
    }

    public function testJsonBodyLimit(): void
    {
        $this->expectException(\Tutora\Http\HttpException::class);
        (new Request('POST', '/x', headers: ['content-type' => 'application/json'], body: '{"a":"' . str_repeat('x', 70000) . '"}'))->json();
    }
}
