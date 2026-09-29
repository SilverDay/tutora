<?php

declare(strict_types=1);

namespace Tutora\Auth;

/** Argon2id password hashing (spec: Auth & Multi-Tenancy). */
final class PasswordHasher
{
    /** @var array{memory_cost:int,time_cost:int,threads:int} */
    private array $options;

    public function __construct(int $memoryKiB = 65536, int $timeCost = 3, int $threads = 1)
    {
        $this->options = ['memory_cost' => $memoryKiB, 'time_cost' => $timeCost, 'threads' => $threads];
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }

    /**
     * Burns comparable CPU time for unknown accounts so login timing does not
     * reveal whether an email is registered.
     */
    public function dummyVerify(string $password): void
    {
        static $dummy = null;
        $dummy ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($password, $dummy);
    }
}
