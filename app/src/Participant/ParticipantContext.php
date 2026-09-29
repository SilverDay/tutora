<?php

declare(strict_types=1);

namespace Tutora\Participant;

/**
 * An authenticated participant, established from a verified participant credential whose
 * session claim matched the requested session and whose row still exists.
 * Never carries a tenant id: participant endpoints are session-scoped by construction.
 */
final class ParticipantContext
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $participantId,
        /** 32 hex chars, opaque per-session moderation/actor id */
        public readonly string $actorId,
    ) {
    }

    public function moderationActorIdBinary(): string
    {
        return (string) hex2bin($this->actorId);
    }
}
