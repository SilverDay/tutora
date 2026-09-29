<?php

declare(strict_types=1);

namespace Tutora\Realtime;

/**
 * Pushes an event to the relay for fan-out to a session's room
 * (spec: PHP -> relay via internal, shared-secret-authenticated POST /internal/broadcast).
 * Must only be called after the corresponding DB transaction has committed.
 */
interface Broadcaster
{
    /** @param array<string,mixed> $message must contain "type" */
    public function broadcast(int $sessionId, array $message): void;
}
