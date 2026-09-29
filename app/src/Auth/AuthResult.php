<?php

declare(strict_types=1);

namespace Tutora\Auth;

final class AuthResult
{
    /** @param list<string> $errors user-facing messages */
    private function __construct(
        public readonly bool $ok,
        public readonly ?AuthStage $stage,
        public readonly array $errors,
        public readonly int $retryAfter = 0,
        /** signup accepted: the same neutral "check your inbox" outcome for every address */
        public readonly bool $verificationPending = false,
    ) {
    }

    public static function verificationPending(): self
    {
        return new self(true, null, [], 0, true);
    }

    public static function stage(AuthStage $stage): self
    {
        return new self(true, $stage, []);
    }

    /** @param list<string> $errors */
    public static function fail(array $errors): self
    {
        return new self(false, null, $errors);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(false, null, ['Too many attempts. Please wait before trying again.'], $retryAfter);
    }
}
