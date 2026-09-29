<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Activity\BlockNotOpen;
use Tutora\Activity\WallService;
use Tutora\Block\BlockType;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Support\FrozenClock;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;

final class WallServiceTest extends TestCase
{
    private LiveSession $live;
    private NullBroadcaster $bc;
    private WallService $wall;
    private int $block;

    protected function setUp(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $this->bc = new NullBroadcaster();
        $this->live = new LiveSession($pdo, $clock, $this->bc, [
            [BlockType::Wall, ['prompt' => 'Retro', 'columns' => ['Good', 'Improve']]],
            [BlockType::Write, ['prompt' => 'x']],
        ]);
        $this->wall = new WallService($pdo, $clock, $this->bc, $this->live->participants);
        $this->block = $this->live->blocks[0];
    }

    private ?TenantDb $other = null;

    private function otherTenant(): TenantDb
    {
        return $this->other ??= new TenantDb($this->live->pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($this->live->pdo, 'x@example.org')));
    }

    public function testAddListAndOwnership(): void
    {
        $a = $this->live->join();
        $b = $this->live->join();
        $c1 = $this->wall->addCard($a, $this->block, '  Great pace ', 'c1');
        $this->wall->addCard($a, $this->block, 'Clear slides', 'c1');
        $c3 = $this->wall->addCard($b, $this->block, 'More breaks', 'c2');

        $forA = $this->wall->cards($this->live->sessionId, $this->block, $a);
        self::assertSame(['Great pace', 'Clear slides', 'More breaks'], array_column($forA, 'text'));
        self::assertSame([0, 1, 0], array_column($forA, 'position'));
        self::assertSame([true, true, false], array_column($forA, 'mine'));
        self::assertArrayNotHasKey('actor', $forA[0], 'no authorship for participants');

        self::assertFalse($this->wall->editCard($b, $c1, 'hijacked'), 'cannot edit others\' cards');
        self::assertFalse($this->wall->deleteCard($b, $c1));
        self::assertFalse($this->wall->moveCard($b, $c1, 'c2', 0));
        self::assertTrue($this->wall->editCard($b, $c3, 'More breaks please'));
        self::assertSame('wall_update', end($this->bc->sent)[1]['type']);
    }

    public function testMoveIsLastWriteWins(): void
    {
        $a = $this->live->join();
        $x = $this->wall->addCard($a, $this->block, 'x', 'c1');
        $y = $this->wall->addCard($a, $this->block, 'y', 'c2');
        $this->wall->addCard($a, $this->block, 'z', 'c2');
        self::assertTrue($this->wall->moveCard($a, $x, 'c2', 0));
        $cards = $this->wall->cards($this->live->sessionId, $this->block, $a);
        self::assertSame(['x', 'y', 'z'], array_column(array_values(array_filter($cards, static fn ($c) => $c['column_id'] === 'c2')), 'text'));
        $this->expectException(ValidationException::class);
        $this->wall->moveCard($a, $y, 'nope', 0);
    }

    public function testValidationAndCap(): void
    {
        $a = $this->live->join();
        foreach ([['', 'c1'], [str_repeat('x', 501), 'c1'], ['ok', 'c9'], [['array'], 'c1']] as [$text, $col]) {
            try {
                $this->wall->addCard($a, $this->block, $text, $col);
                self::fail('expected rejection');
            } catch (ValidationException) {
            }
        }
        for ($i = 0; $i < WallService::MAX_CARDS_PER_PARTICIPANT; $i++) {
            $this->wall->addCard($a, $this->block, "card {$i}", 'c1');
        }
        $this->expectException(ValidationException::class);
        $this->wall->addCard($a, $this->block, 'one too many', 'c1');
    }

    public function testOnlyCurrentWallAcceptsParticipantInput(): void
    {
        $a = $this->live->join();
        $this->live->goTo(1);
        $this->expectException(BlockNotOpen::class);
        $this->wall->addCard($a, $this->block, 'late', 'c1');
    }

    public function testTutorModerationIsTenantScoped(): void
    {
        $a = $this->live->join();
        $b = $this->live->join();
        $this->wall->addCard($a, $this->block, 'spam 1', 'c1');
        $this->wall->addCard($a, $this->block, 'spam 2', 'c2');
        $keep = $this->wall->addCard($b, $this->block, 'useful', 'c1');

        self::assertNull($this->wall->tutorAddCard($this->otherTenant(), $this->live->sessionId, $this->block, 'x', 'c1'));
        self::assertFalse($this->wall->tutorDeleteCard($this->otherTenant(), $this->live->sessionId, $keep));
        self::assertSame([], $this->wall->removeActor($this->otherTenant(), $this->live->sessionId, $a->actorId));
        self::assertCount(3, $this->wall->cards($this->live->sessionId, $this->block, null));

        $tutorView = $this->wall->cards($this->live->sessionId, $this->block, null, true);
        self::assertSame($a->actorId, $tutorView[0]['actor']);

        self::assertSame([$this->block], $this->wall->removeActor($this->live->tenantDb, $this->live->sessionId, $a->actorId));
        self::assertSame(['useful'], array_column($this->wall->cards($this->live->sessionId, $this->block, null), 'text'));

        $t = $this->wall->tutorAddCard($this->live->tenantDb, $this->live->sessionId, $this->block, 'Seed card', 'c2');
        self::assertNotNull($t);
        self::assertTrue($this->wall->tutorMoveCard($this->live->tenantDb, $this->live->sessionId, $keep, 'c2', 0));
        self::assertTrue($this->wall->tutorDeleteCard($this->live->tenantDb, $this->live->sessionId, $t));
        self::assertFalse($this->wall->editCard($b, $t, 'x'), 'participants cannot edit tutor cards');
    }

    public function testCannotTouchCardsOfAnotherSession(): void
    {
        $other = new LiveSession($this->live->pdo, $this->live->clock, $this->bc, [[BlockType::Wall, ['columns' => ['A']]]], 'o@example.org');
        $o = $other->join();
        $foreign = $this->wall->addCard($o, $other->blocks[0], 'foreign', 'c1');
        $a = $this->live->join();
        self::assertFalse($this->wall->deleteCard($a, $foreign));
        self::assertFalse($this->wall->tutorDeleteCard($this->live->tenantDb, $this->live->sessionId, $foreign));
        $this->expectException(BlockNotOpen::class);
        $this->wall->addCard($a, $other->blocks[0], 'x', 'c1');
    }
}
