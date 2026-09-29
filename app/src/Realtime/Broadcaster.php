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
    public const TARGET_TUTOR = 'tutor';
    public const TARGET_PARTICIPANT = 'participant';

    /**
     * @param array<string,mixed> $message must contain "type"
     * @param self::TARGET_*|null $targetRole null = everyone in the room
     */
    public function broadcast(int $sessionId, array $message, ?string $targetRole = null): void;
}
