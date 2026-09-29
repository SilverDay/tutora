<?php

declare(strict_types=1);

namespace Tutora\Realtime;

/** Records messages instead of sending them (tests, and when no relay is configured). */
final class NullBroadcaster implements Broadcaster
{
    /** @var list<array{int,array<string,mixed>,?string}> */
    public array $sent = [];

    public function broadcast(int $sessionId, array $message, ?string $targetRole = null): void
    {
        $this->sent[] = [$sessionId, $message, $targetRole];
    }
}
