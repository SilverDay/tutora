<?php

declare(strict_types=1);

namespace Tutora\Realtime;

/** Records messages instead of sending them (tests, and until the relay is configured). */
final class NullBroadcaster implements Broadcaster
{
    /** @var list<array{int,array<string,mixed>}> */
    public array $sent = [];

    public function broadcast(int $sessionId, array $message): void
    {
        $this->sent[] = [$sessionId, $message];
    }
}
