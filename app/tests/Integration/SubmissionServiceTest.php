<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Activity\BlockNotOpen;
use Tutora\Activity\SubmissionService;
use Tutora\Block\BlockType;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Support\FrozenClock;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Activity\BlockStates;
use Tutora\Activity\QuizService;
use Tutora\Activity\WallService;

final class SubmissionServiceTest extends TestCase
{
    private LiveSession $live;
    private NullBroadcaster $bc;
    private SubmissionService $svc;

    protected function setUp(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $this->bc = new NullBroadcaster();
        $this->live = new LiveSession($pdo, $clock, $this->bc, [
            [BlockType::Poll, ['question' => 'Ready?', 'options' => ['Yes', 'No']]],
            [BlockType::Write, ['prompt' => 'Thoughts?']],
            [BlockType::Meter, ['prompt' => 'Confidence', 'min' => 0, 'max' => 10, 'step' => 1]],
        ]);
        $this->svc = new SubmissionService($pdo, $clock, $this->bc, $this->live->participants);
    }

    public function testSubmitUpsertsAndBroadcastsAggregate(): void
    {
        $a = $this->live->join();
        $b = $this->live->join();
        $poll = $this->live->blocks[0];
        $this->svc->submit($a, $poll, ['selected' => ['o1']]);
        $this->svc->submit($b, $poll, ['selected' => ['o1']]);
        $this->svc->submit($a, $poll, ['selected' => ['o2']]); // revise
        self::assertSame(2, (int) $this->live->pdo->query('SELECT COUNT(*) FROM block_submissions')->fetchColumn());
        self::assertSame(['selected' => ['o2']], $this->svc->mine($a, $poll));

        $last = end($this->bc->sent);
        self::assertSame('activity_aggregate_update', $last[1]['type']);
        self::assertSame($poll, $last[1]['session_block_id']);
        self::assertSame([1, 1], array_column($last[1]['aggregate']['options'], 'count'));
        self::assertStringNotContainsString($a->actorId, json_encode($last[1]), 'aggregate is anonymous');
    }

    public function testOnlyCurrentBlockAcceptsInput(): void
    {
        $a = $this->live->join();
        $this->expectException(BlockNotOpen::class);
        $this->svc->submit($a, $this->live->blocks[2], ['value' => 5]);
    }

    public function testMovingAwayClosesBlock(): void
    {
        $a = $this->live->join();
        $this->live->goTo(2);
        $this->svc->submit($a, $this->live->blocks[2], ['value' => 5]);
        $this->expectException(BlockNotOpen::class);
        $this->svc->submit($a, $this->live->blocks[0], ['selected' => ['o1']]);
    }

    public function testEndedSessionRejectsInput(): void
    {
        $a = $this->live->join();
        $this->live->sessions->end($this->live->sessionId);
        $this->expectException(BlockNotOpen::class);
        $this->svc->submit($a, $this->live->blocks[0], ['selected' => ['o1']]);
    }

    public function testInvalidPayloadNotStored(): void
    {
        $a = $this->live->join();
        try {
            $this->svc->submit($a, $this->live->blocks[0], ['selected' => ['nope']]);
            self::fail('expected validation error');
        } catch (ValidationException) {
        }
        self::assertSame(0, (int) $this->live->pdo->query('SELECT COUNT(*) FROM block_submissions')->fetchColumn());
    }

    public function testCannotSubmitToAnotherSessionsBlock(): void
    {
        $other = new LiveSession($this->live->pdo, $this->live->clock, $this->bc, [[BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b']]]], 'other@example.org');
        $a = $this->live->join();
        $this->expectException(BlockNotOpen::class);
        $this->svc->submit($a, $other->blocks[0], ['selected' => ['o1']]);
    }

    public function testWriteTextsAreTutorOnlyAndTenantScoped(): void
    {
        $a = $this->live->join();
        $this->live->goTo(1);
        $write = $this->live->blocks[1];
        $this->svc->submit($a, $write, ['text' => 'my private reflection']);
        $broadcast = json_encode($this->bc->sent);
        self::assertStringNotContainsString('my private reflection', $broadcast);
        self::assertSame(['responses' => 1], $this->svc->aggregate($this->live->sessionId, $write));

        $responses = $this->svc->writeResponses($this->live->tenantDb, $this->live->sessionId, $write);
        self::assertSame('my private reflection', $responses[0]['text']);
        self::assertSame($a->actorId, $responses[0]['actor']);

        $otherTenant = new TenantDb($this->live->pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($this->live->pdo, 'x@example.org')));
        self::assertSame([], $this->svc->writeResponses($otherTenant, $this->live->sessionId, $write));
    }

    public function testRemoveActorContributions(): void
    {
        $a = $this->live->join();
        $b = $this->live->join();
        $poll = $this->live->blocks[0];
        $this->svc->submit($a, $poll, ['selected' => ['o1']]);
        $this->svc->submit($b, $poll, ['selected' => ['o2']]);

        $otherTenant = new TenantDb($this->live->pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($this->live->pdo, 'x@example.org')));
        self::assertSame([], $this->svc->removeActor($otherTenant, $this->live->sessionId, $a->actorId), 'other tenant cannot moderate');
        self::assertSame(2, $this->svc->aggregate($this->live->sessionId, $poll)['responses']);

        self::assertSame([$poll], $this->svc->removeActor($this->live->tenantDb, $this->live->sessionId, $a->actorId));
        $agg = $this->svc->aggregate($this->live->sessionId, $poll);
        self::assertSame(1, $agg['responses']);
        self::assertSame([0, 1], array_column($agg['options'], 'count'));
    }

    public function testSubmissionCountsAsActivity(): void
    {
        $a = $this->live->join();
        $this->live->clock->advance('PT25M');
        $this->svc->submit($a, $this->live->blocks[0], ['selected' => ['o1']]);
        $exp = $this->live->pdo->query("SELECT resume_token_expires_at FROM session_participants WHERE id = {$a->participantId}")->fetchColumn();
        self::assertSame('2026-03-01 10:55:00.000', $exp);
    }

    public function testHiddenResultsReachOnlyTheTutorUntilRevealed(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $bc = new NullBroadcaster();
        $live = new LiveSession($pdo, $clock, $bc, [
            [BlockType::Poll, ['question' => 'Guess?', 'options' => ['Yes', 'No'], 'results' => 'on_reveal']],
            [BlockType::Poll, ['question' => 'Live?', 'options' => ['Yes', 'No']]],
        ]);
        $svc = new SubmissionService($pdo, $clock, $bc, $live->participants);
        $states = new BlockStates($svc, new WallService($pdo, $clock, $bc, $live->participants), new QuizService($pdo, $clock, $bc, $live->participants),
            new \Tutora\Ai\SummaryService($pdo, $clock, null, $svc, $bc, new \Tutora\Audit\AuditLog($pdo, $clock), new \Tutora\Security\Logger(static fn () => null)));
        [$hidden, $visible] = $live->blocks;
        $a = $live->join();

        $svc->submit($a, $hidden, ['selected' => ['o1']]);
        $last = end($bc->sent);
        self::assertSame('tutor', $last[2], 'aggregate of a hidden block goes to the tutor only');
        self::assertTrue($svc->resultsHidden($live->sessionId, $hidden));
        $p = $states->forParticipant($a, $hidden, BlockType::Poll);
        self::assertNull($p['aggregate']);
        self::assertTrue($p['results_hidden']);
        self::assertSame(['selected' => ['o1']], $p['mine'], 'own answer still shown');
        $t = $states->forTutor($live->tenantDb, $live->sessionId, $hidden, BlockType::Poll);
        self::assertSame(1, $t['aggregate']['responses']);
        self::assertTrue($t['results_hidden']);

        // other tenant cannot reveal; a live block is not "revealable"
        $other = new TenantDb($pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($pdo, 'other@example.org')));
        self::assertFalse($svc->revealResults($other, $live->sessionId, $hidden));
        self::assertFalse($svc->revealResults($live->tenantDb, $live->sessionId, $visible));
        self::assertTrue($svc->resultsHidden($live->sessionId, $hidden));

        self::assertTrue($svc->revealResults($live->tenantDb, $live->sessionId, $hidden));
        $last = end($bc->sent);
        self::assertNull($last[2], 'revealed aggregate goes to everyone');
        self::assertSame(1, $last[1]['aggregate']['responses']);
        self::assertFalse($svc->resultsHidden($live->sessionId, $hidden));
        self::assertSame(1, $states->forParticipant($a, $hidden, BlockType::Poll)['aggregate']['responses']);
        self::assertTrue($svc->revealResults($live->tenantDb, $live->sessionId, $hidden), 'idempotent');

        $live->goTo(1);
        $svc->submit($a, $visible, ['selected' => ['o2']]);
        self::assertNull(end($bc->sent)[2], 'live block unchanged');
    }
}
