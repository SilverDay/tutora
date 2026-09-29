<?php

declare(strict_types=1);

namespace Tutora\Participant;

final class JoinFailure
{
    private function __construct(public readonly string $message, public readonly int $retryAfter)
    {
    }

    public static function invalid(string $message): self
    {
        return new self($message, 0);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self('Too many attempts. Please wait a moment and try again.', $retryAfter);
    }
}
