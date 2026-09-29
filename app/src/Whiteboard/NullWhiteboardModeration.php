<?php

declare(strict_types=1);

namespace Tutora\Whiteboard;

/** Records calls (tests / when no sidecar is configured). */
final class NullWhiteboardModeration implements WhiteboardModeration
{
    /** @var list<array<int|string>> */
    public array $calls = [];

    public function clear(int $sessionId, int $blockId): void
    {
        $this->calls[] = ['clear', $sessionId, $blockId];
    }

    public function removeActor(int $sessionId, string $actorHex): void
    {
        $this->calls[] = ['remove_actor', $sessionId, $actorHex];
    }

    public function endSession(int $sessionId): void
    {
        $this->calls[] = ['end_session', $sessionId];
    }

    public function dropSession(int $sessionId): void
    {
        $this->calls[] = ['drop_session', $sessionId];
    }
}
