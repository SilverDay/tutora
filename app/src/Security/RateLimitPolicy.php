<?php

declare(strict_types=1);

namespace Tutora\Security;

final class RateLimitPolicy
{
    public function __construct(
        public readonly int $freeAttempts,
        public readonly int $baseDelaySeconds,
        public readonly int $maxDelaySeconds,
        public readonly int $windowSeconds = 86400,
    ) {
    }

    /** Per source IP: generous, since NAT/classrooms share IPs. */
    public static function loginPerIp(): self
    {
        return new self(20, 1, 900);
    }

    /** Per account: tighter; also covers TOTP guessing. */
    public static function loginPerAccount(): self
    {
        return new self(5, 2, 900);
    }
}
