<?php

declare(strict_types=1);

namespace Tutora\Auth;

use PDO;
use Tutora\Realtime\Broadcaster;
use Tutora\Whiteboard\WhiteboardModeration;

/**
 * After a credential change has ended a tutor's other HTTP sessions (auth epoch), this
 * also closes the tutor's open realtime connections: relay and whiteboard sidecar
 * disconnect every tutor socket of the tenant's live sessions and refuse tutor tokens
 * issued before now. Tokens are only issued for live sessions, so these are all of them.
 *
 * The calls are best effort (failures are logged as errors): the HTTP session check is the
 * primary control and already blocks every state-changing request and new tokens.
 */
final class TutorRealtimeRevoker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Broadcaster $relay,
        private readonly WhiteboardModeration $whiteboard,
    ) {
    }

    public function revokeAll(int $tenantId): void
    {
        $s = $this->pdo->prepare("SELECT id FROM sessions WHERE tenant_id = ? AND status = 'live'");
        $s->execute([$tenantId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
            $this->relay->revokeTutor((int) $sessionId);
            $this->whiteboard->revokeTutor((int) $sessionId);
        }
    }
}
