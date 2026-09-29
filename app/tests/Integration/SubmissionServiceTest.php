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
}
