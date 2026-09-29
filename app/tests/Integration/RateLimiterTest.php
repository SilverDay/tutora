<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Security\RateLimiter;
use Tutora\Security\RateLimitPolicy;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\TestDatabase;

final class RateLimiterTest extends TestCase
{
    public function testExponentialBackoffAfterFreeAttempts(): void
    {
        $clock = new FrozenClock();
        $rl = new RateLimiter(TestDatabase::reset(), $clock);
        $policy = new RateLimitPolicy(3, 2, 60);

        for ($i = 0; $i < 3; $i++) {
            $rl->recordFailure('k', $policy);
            self::assertSame(0, $rl->retryAfter('k'));
        }
        $rl->recordFailure('k', $policy);
        self::assertSame(2, $rl->retryAfter('k'));
        $rl->recordFailure('k', $policy);
        self::assertSame(4, $rl->retryAfter('k'));
        for ($i = 0; $i < 10; $i++) {
            $rl->recordFailure('k', $policy);
        }
        self::assertSame(60, $rl->retryAfter('k'), 'capped, never a hard lockout');
        $clock->advance('PT61S');
        self::assertSame(0, $rl->retryAfter('k'));
        self::assertSame(0, $rl->retryAfter('other'));
    }

    public function testWindowExpiryResetsCount(): void
    {
        $clock = new FrozenClock();
        $rl = new RateLimiter(TestDatabase::reset(), $clock);
        $policy = new RateLimitPolicy(1, 10, 100, 3600);
        $rl->recordFailure('k', $policy);
        $clock->advance('PT2H');
        $rl->recordFailure('k', $policy);
        self::assertSame(0, $rl->retryAfter('k'));
    }

    public function testConsumeFixedWindow(): void
    {
        $clock = new FrozenClock();
        $rl = new RateLimiter(TestDatabase::reset(), $clock);
        self::assertTrue($rl->consume('j', 2, 60));
        self::assertTrue($rl->consume('j', 2, 60));
        self::assertFalse($rl->consume('j', 2, 60));
        $clock->advance('PT60S');
        self::assertTrue($rl->consume('j', 2, 60));
    }

    public function testKeysStoredHashed(): void
    {
        $pdo = TestDatabase::reset();
        (new RateLimiter($pdo, new FrozenClock()))->recordFailure('login-ip:203.0.113.9', RateLimitPolicy::loginPerIp());
        $key = $pdo->query('SELECT bucket_key FROM rate_limit_buckets')->fetchColumn();
        self::assertSame(hash('sha256', 'login-ip:203.0.113.9', true), $key);
    }
}
