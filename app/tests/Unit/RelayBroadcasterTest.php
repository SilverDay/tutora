<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Realtime\RelayBroadcaster;
use Tutora\Security\Logger;

final class RelayBroadcasterTest extends TestCase
{
    private const SECRET = 'internal-secret-internal-secret-0123';

    public function testPostsSessionMessageAndTarget(): void
    {
        $calls = [];
        $b = new RelayBroadcaster('http://relay:8081/', self::SECRET, new Logger(static fn () => null), static function (string $url, string $body, string $secret) use (&$calls): int {
            $calls[] = [$url, json_decode($body, true), $secret];
            return 202;
        });
        $b->broadcast(7, ['type' => 'capture', 'session_block_id' => 3], 'tutor');
        self::assertSame('http://relay:8081/internal/broadcast', $calls[0][0]);
        self::assertSame(['session_id' => 7, 'message' => ['type' => 'capture', 'session_block_id' => 3], 'target_role' => 'tutor'], $calls[0][1]);
        self::assertSame(self::SECRET, $calls[0][2]);
    }

    public function testFailureIsLoggedWithoutPayloadAndNotThrown(): void
    {
        $lines = [];
        $b = new RelayBroadcaster('http://relay:8081', self::SECRET, new Logger(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }), static fn () => throw new \RuntimeException('down'));
        $b->broadcast(7, ['type' => 'activity_aggregate_update', 'words' => ['secret participant text']]);
        self::assertCount(1, $lines);
        self::assertStringContainsString('activity_aggregate_update', $lines[0]);
        self::assertStringNotContainsString('secret participant text', $lines[0]);
    }

    public function testNonAcceptedStatusLogged(): void
    {
        $lines = [];
        $b = new RelayBroadcaster('http://relay:8081', self::SECRET, new Logger(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }), static fn () => 401);
        $b->broadcast(7, ['type' => 'block_change']);
        self::assertStringContainsString('401', $lines[0]);
    }

    public function testShortSecretRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RelayBroadcaster('http://relay', 'short', new Logger(static fn () => null));
    }
}
