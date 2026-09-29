<?php

declare(strict_types=1);

namespace Tutora\Auth;

/**
 * NIST SP 800-63B-style policy: length bounds and breached-password screening,
 * no composition rules, no periodic rotation.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    public function __construct(
        private readonly BreachedPasswordChecker $breachChecker,
        private readonly bool $failOpen = false,
    ) {
    }

    /**
     * @return list<string> user-facing error messages (empty = acceptable)
     */
    public function validate(string $password, string $email): array
    {
        $errors = [];
        $len = mb_strlen($password, 'UTF-8');
        if (!mb_check_encoding($password, 'UTF-8')) {
            return ['Password contains invalid characters.'];
        }
        if ($len < self::MIN_LENGTH) {
            $errors[] = sprintf('Password must be at least %d characters long.', self::MIN_LENGTH);
        }
        if ($len > self::MAX_LENGTH) {
            $errors[] = sprintf('Password must be at most %d characters long.', self::MAX_LENGTH);
        }
        $local = strtolower(explode('@', $email)[0]);
        if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
            $errors[] = 'Password must not contain your email address.';
        }
        if ($errors !== []) {
            return $errors;
        }
        try {
            if ($this->breachChecker->isBreached($password)) {
                $errors[] = 'This password appears in a known data breach. Please choose a different one.';
            }
        } catch (BreachCheckUnavailable) {
            if (!$this->failOpen) {
                $errors[] = 'The password could not be checked right now. Please try again in a few minutes.';
            }
        }
        return $errors;
    }
}
