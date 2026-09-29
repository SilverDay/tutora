<?php

declare(strict_types=1);

namespace Tutora\Whiteboard;

/** Calls into the whiteboard sidecar's internal API (best effort, like the relay broadcaster). */
interface WhiteboardModeration
{
    public function clear(int $sessionId, int $blockId): void;

    public function removeActor(int $sessionId, string $actorHex): void;

    public function endSession(int $sessionId): void;

    public function dropSession(int $sessionId): void;

    /** Closes all tutor connections of a session and refuses tutor tokens issued until now. */
    public function revokeTutor(int $sessionId): void;
}
