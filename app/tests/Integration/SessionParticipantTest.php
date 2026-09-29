<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Audit\AuditLog;
use Tutora\Block\BlockType;
use Tutora\Participant\JoinFailure;
use Tutora\Participant\ParticipantService;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Security\HmacToken;
use Tutora\Security\RateLimiter;
use Tutora\Session\JoinCode;
use Tutora\Session\SessionService;
use Tutora\Support\FrozenClock;
use Tutora\Support\Time;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Workshop\WorkshopRepository;

final class SessionParticipantTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private NullBroadcaster $bc;
    private WorkshopRepository $workshops;
    private SessionService $sessions;
    private SessionService $otherTenantSessions;
    private ParticipantService $participants;
    private HmacToken $credTokens;
    private HmacToken $relayTokens;
    private int $workshopId;
    /** @var list<int> */
    private array $templateBlocks = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->bc = new NullBroadcaster();
        $ta = Fixtures::tenant($this->pdo, 'a@example.org');
        $tb = Fixtures::tenant($this->pdo, 'b@example.org');
        $dbA = new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor($ta));
        $audit = new AuditLog($this->pdo, $this->clock);
        $this->workshops = new WorkshopRepository($dbA, $this->clock);
        $this->sessions = new SessionService($dbA, $this->clock, $this->bc, $audit, 30);
        $this->otherTenantSessions = new SessionService(new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor($tb)), $this->clock, $this->bc, $audit, 30);
        $this->credTokens = new HmacToken(str_repeat('p', 32), HmacToken::AUD_PARTICIPANT, $this->clock);
        $this->relayTokens = new HmacToken(str_repeat('r', 32), HmacToken::AUD_RELAY, $this->clock);
        $this->participants = new ParticipantService($this->pdo, $this->clock, $this->credTokens, $this->relayTokens, new RateLimiter($this->pdo, $this->clock));

        $this->workshopId = $this->workshops->create('Security 101', null);
        $this->templateBlocks[] = $this->workshops->addBlock($this->workshopId, BlockType::Write, ['prompt' => 'Warm-up']);
        $this->templateBlocks[] = $this->workshops->addBlock($this->workshopId, BlockType::Quiz, ['questions' => [
            ['type' => 'single', 'prompt' => 'Best hash for passwords?', 'options' => ['MD5', 'Argon2id'], 'correct_answer' => 'o2'],
        ]]);
    }

    private function code(int $sessionId): string
    {
        return $this->sessions->find($sessionId)['join_code'];
    }

    public function testStartCreatesImmutableSnapshot(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        self::assertMatchesRegularExpression('/^[' . JoinCode::ALPHABET . ']{6}$/', $this->code($sid));
        $blocks = $this->sessions->blocks($sid);
        self::assertCount(2, $blocks);
        self::assertSame('Warm-up', $blocks[0]['config_snapshot']['prompt']);
        self::assertSame((int) $blocks[0]['id'], (int) $this->sessions->find($sid)['current_session_block_id']);

        // editing and deleting the template does not affect the running session
        $this->workshops->updateBlock($this->templateBlocks[0], ['prompt' => 'Edited later']);
        $this->workshops->deleteBlock($this->templateBlocks[1]);
        $this->workshops->delete($this->workshopId);
        $after = $this->sessions->blocks($sid);
        self::assertCount(2, $after);
        self::assertSame('Warm-up', $after[0]['config_snapshot']['prompt']);
        self::assertNull($after[1]['source_workshop_block_id']);
        self::assertSame('Security 101', $this->sessions->find($sid)['workshop_title_snapshot']);
    }

    public function testNavigationBumpsRevisionAndBroadcasts(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $blocks = $this->sessions->blocks($sid);
        $rev = $this->sessions->goToBlock($sid, (int) $blocks[1]['id']);
        self::assertSame(2, $rev);
        self::assertSame([$sid, ['type' => 'block_change', 'session_revision' => 2, 'session_block_id' => (int) $blocks[1]['id']], null], $this->bc->sent[0]);
        self::assertNull($this->sessions->step($sid, 1), 'no block after the last');
        self::assertSame(3, $this->sessions->step($sid, -1));
    }

    public function testCannotNavigateToBlockOfAnotherSession(): void
    {
        $s1 = $this->sessions->start($this->workshopId);
        $s2 = $this->sessions->start($this->workshopId);
        $foreign = (int) $this->sessions->blocks($s2)[1]['id'];
        self::assertNull($this->sessions->goToBlock($s1, $foreign));
        self::assertNotSame($this->code($s1), $this->code($s2));
    }

    public function testOtherTenantCannotSeeOrControlSession(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        self::assertNull($this->otherTenantSessions->find($sid));
        self::assertSame([], $this->otherTenantSessions->blocks($sid));
        self::assertNull($this->otherTenantSessions->tutorState($sid));
        self::assertNull($this->otherTenantSessions->goToBlock($sid, (int) $this->sessions->blocks($sid)[1]['id']));
        self::assertFalse($this->otherTenantSessions->end($sid));
        self::assertFalse($this->otherTenantSessions->delete($sid, '198.51.100.1'));
        self::assertNull($this->otherTenantSessions->start($this->workshopId));
        self::assertSame('live', $this->sessions->find($sid)['status']);
    }

    public function testJoinAndParticipantStateStripsAnswerKey(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $joined = $this->participants->join(strtolower($this->code($sid)), '  Anna ', '198.51.100.9');
        self::assertIsArray($joined);
        self::assertSame($sid, $joined['session_id']);
        self::assertGreaterThanOrEqual(43, strlen($joined['resume_token']), '>=256-bit token');

        $ctx = $this->participants->authenticate($joined['credential'], $sid);
        self::assertNotNull($ctx);
        $this->sessions->step($sid, 1);
        $state = $this->participants->state($ctx);
        self::assertSame('quiz', $state['current_block']['type']);
        self::assertStringNotContainsString('correct_answer', json_encode($state));
        self::assertStringContainsString('correct_answer', json_encode($this->sessions->tutorState($sid)));

        // stored as hash only
        $row = $this->pdo->query('SELECT resume_token_hash, display_name FROM session_participants')->fetch();
        self::assertSame(hash('sha256', $joined['resume_token'], true), $row['resume_token_hash']);
        self::assertSame('Anna', $row['display_name']);
    }

    public function testCredentialBoundToItsSession(): void
    {
        $s1 = $this->sessions->start($this->workshopId);
        $s2 = $this->sessions->start($this->workshopId);
        $j = $this->participants->join($this->code($s1), null, '198.51.100.9');
        self::assertNull($this->participants->authenticate($j['credential'], $s2), 'bare session id is never authorization');
        self::assertNull($this->participants->authenticate(null, $s1));
        self::assertNull($this->participants->authenticate('garbage', $s1));
        // a relay token (different audience/key) is not a participant credential
        $ctx = $this->participants->authenticate($j['credential'], $s1);
        self::assertNull($this->participants->authenticate($this->participants->connectionToken($ctx), $s1));
    }

    public function testCredentialExpiresAndSessionEndRevokes(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $j = $this->participants->join($this->code($sid), null, '198.51.100.9');
        $this->clock->advance('PT21M');
        self::assertNull($this->participants->authenticate($j['credential'], $sid));

        $j2 = $this->participants->join($this->code($sid), null, '198.51.100.9');
        $this->sessions->end($sid);
        self::assertNull($this->participants->authenticate($j2['credential'], $sid), 'ended session revokes access');
        self::assertIsObject($this->participants->join($this->code($sid) ?? 'ZZZZZZ', null, '198.51.100.9'));
    }

    public function testResumeRotatesTokenAndHonoursWindow(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $j = $this->participants->join($this->code($sid), null, '198.51.100.9');

        $this->clock->advance('PT29M');
        $r = $this->participants->resume($j['resume_token'], '198.51.100.9');
        self::assertNotNull($r);
        self::assertNotSame($j['resume_token'], $r['resume_token']);
        self::assertNull($this->participants->resume($j['resume_token'], '198.51.100.9'), 'old token no longer valid after rotation');

        // resume itself is not activity: window is still joined_at + 30 min
        $this->clock->advance('PT2M');
        self::assertNull($this->participants->resume($r['resume_token'], '198.51.100.9'));
    }

    public function testPresenceRenewalExtendsResumeWindow(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $j = $this->participants->join($this->code($sid), null, '198.51.100.9');
        for ($i = 0; $i < 4; $i++) {
            $this->clock->advance('PT15M');
            $ctx = $this->participants->authenticate($j['credential'], $sid);
            self::assertNotNull($ctx, 'credential refreshed by presence renewal');
            $j['credential'] = $this->participants->renewPresence($ctx)['credential'];
        }
        // 60 min after join, still resumable thanks to presence
        $this->clock->advance('PT29M');
        self::assertNotNull($this->participants->resume($j['resume_token'], '198.51.100.9'));
    }

    public function testResumeWindowCappedAtSessionEndPlus15Minutes(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $j = $this->participants->join($this->code($sid), null, '198.51.100.9');
        $this->sessions->end($sid);
        $exp = Time::fromDb($this->pdo->query('SELECT resume_token_expires_at FROM session_participants')->fetchColumn());
        self::assertEquals($this->clock->now()->modify('+15 minutes'), $exp);
        // and the session is no longer live, so resume is refused outright
        self::assertNull($this->participants->resume($j['resume_token'], '198.51.100.9'));
    }

    public function testResumeExpiryFormula(): void
    {
        $t = $this->clock->now();
        self::assertEquals($t->modify('+30 minutes'), ParticipantService::resumeExpiry($t, $t->modify('-5 minutes'), null));
        self::assertEquals($t->modify('+40 minutes'), ParticipantService::resumeExpiry($t, $t->modify('+10 minutes'), null));
        self::assertEquals($t->modify('+20 minutes'), ParticipantService::resumeExpiry($t, $t, $t->modify('+5 minutes')));
    }

    public function testEndSetsRetentionAndReleasesJoinCode(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $this->sessions->end($sid);
        $s = $this->sessions->find($sid);
        self::assertSame('ended', $s['status']);
        self::assertNull($s['active_join_code']);
        self::assertEquals($this->clock->now()->modify('+30 days'), Time::fromDb($s['expires_at']));
        self::assertFalse($this->sessions->end($sid), 'already ended');
        self::assertNull($this->sessions->goToBlock($sid, (int) $this->sessions->blocks($sid)[1]['id']));
    }

    public function testPerTenantRetentionOverride(): void
    {
        $this->pdo->exec("UPDATE tenants SET retention_days = 7 WHERE email = 'a@example.org'");
        $sid = $this->sessions->start($this->workshopId);
        $this->sessions->end($sid);
        self::assertEquals($this->clock->now()->modify('+7 days'), Time::fromDb($this->sessions->find($sid)['expires_at']));
    }

    public function testWrongCodesAreThrottledPerIp(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        for ($i = 0; $i < 11; $i++) {
            $r = $this->participants->join('ZZZZZZ', null, '203.0.113.5');
            self::assertInstanceOf(JoinFailure::class, $r);
        }
        $r = $this->participants->join($this->code($sid), null, '203.0.113.5');
        self::assertInstanceOf(JoinFailure::class, $r, 'even a correct code is refused while backing off');
        self::assertGreaterThan(0, $r->retryAfter);
        self::assertIsArray($this->participants->join($this->code($sid), null, '203.0.113.6'), 'other IPs unaffected');
    }

    public function testJoinBudgetPerIp(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        for ($i = 0; $i < ParticipantService::JOIN_BUDGET_PER_IP; $i++) {
            self::assertIsArray($this->participants->join($this->code($sid), null, '203.0.113.7'));
        }
        self::assertInstanceOf(JoinFailure::class, $this->participants->join($this->code($sid), null, '203.0.113.7'));
    }

    public function testDisplayNameLimits(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        self::assertInstanceOf(JoinFailure::class, $this->participants->join($this->code($sid), str_repeat('x', 41), '198.51.100.9'));
        self::assertInstanceOf(JoinFailure::class, $this->participants->join($this->code($sid), "a\u{0007}b", '198.51.100.9'));
    }

    public function testConnectionTokenClaims(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $j = $this->participants->join($this->code($sid), null, '198.51.100.9');
        $ctx = $this->participants->authenticate($j['credential'], $sid);
        $claims = $this->relayTokens->verify($this->participants->connectionToken($ctx));
        self::assertSame($sid, $claims['sid']);
        self::assertSame('participant', $claims['role']);
        self::assertSame($ctx->actorId, $claims['actor']);
        self::assertSame($this->clock->now()->getTimestamp() + 60, $claims['exp']);
    }

    public function testDeleteSessionCascadesAndAudits(): void
    {
        $sid = $this->sessions->start($this->workshopId);
        $this->participants->join($this->code($sid), null, '198.51.100.9');
        self::assertTrue($this->sessions->delete($sid, '198.51.100.1'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM session_participants')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE event_type = 'session.deleted'")->fetchColumn());
    }

    public function testStartRequiresBlocks(): void
    {
        $empty = $this->workshops->create('Empty', null);
        $this->expectException(\Tutora\Support\ValidationException::class);
        $this->sessions->start($empty);
    }

    public function testJoinCodeNormalisation(): void
    {
        self::assertSame('ABCDEF', JoinCode::normalize(' abc-def '));
        self::assertNull(JoinCode::normalize('ABCDE0'), 'ambiguous 0 not in alphabet');
        self::assertNull(JoinCode::normalize('ABCDEFG'));
    }
}
