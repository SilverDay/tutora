<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Block\BlockType;
use Tutora\Auth\TutorRealtimeRevoker;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Whiteboard\NullWhiteboardModeration;

final class TutorRealtimeRevokerTest extends TestCase
{
    public function testRevokesTutorConnectionsOfTheTenantsLiveSessionsOnly(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $bc = new NullBroadcaster();
        $blocks = [[BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b']]]];
        $live = new LiveSession($pdo, $clock, $bc, $blocks);
        $ended = new LiveSession($pdo, $clock, $bc, $blocks, 'second@example.org');
        // move the second session to the first tenant, then end it
        $tenantId = (int) $pdo->query("SELECT tenant_id FROM sessions WHERE id = {$live->sessionId}")->fetchColumn();
        $ended->sessions->end($ended->sessionId);
        $pdo->prepare('UPDATE sessions SET tenant_id = ? WHERE id = ?')->execute([$tenantId, $ended->sessionId]);
        $foreign = new LiveSession($pdo, $clock, $bc, $blocks, 'other@example.org');

        $relay = new NullBroadcaster();
        $wb = new NullWhiteboardModeration();
        (new TutorRealtimeRevoker($pdo, $relay, $wb))->revokeAll($tenantId);

        self::assertSame([$live->sessionId], $relay->revokedTutor, 'only the live session of this tenant');
        self::assertSame([['revoke_tutor', $live->sessionId]], $wb->calls);
        self::assertNotContains($foreign->sessionId, $relay->revokedTutor);
    }
}
