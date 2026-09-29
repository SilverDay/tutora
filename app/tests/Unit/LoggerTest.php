<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Security\HmacToken;
use Tutora\Security\Logger;
use Tutora\Support\FrozenClock;

final class LoggerTest extends TestCase
{
    public function testRedactsSensitiveContextKeysRecursively(): void
    {
        $out = Logger::redact([
            'session_id' => 7,
            'resume_token' => 'abc',
            'nested' => ['answer_payload' => ['x' => 1], 'ok' => 'fine'],
            'Authorization' => 'Bearer x',
            'context' => 'kept',
        ]);
        self::assertSame(7, $out['session_id']);
        self::assertSame('[REDACTED]', $out['resume_token']);
        self::assertSame('[REDACTED]', $out['nested']['answer_payload']);
        self::assertSame('fine', $out['nested']['ok']);
        self::assertSame('[REDACTED]', $out['Authorization']);
        self::assertSame('kept', $out['context']);
    }

    public function testScrubsTokensFromMessages(): void
    {
        $token = (new HmacToken(str_repeat('k', 32), HmacToken::AUD_RELAY, new FrozenClock()))->issue(['session_id' => 1], 60);
        $lines = [];
        $logger = new Logger(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });
        $logger->warning("bad token {$token} from /ws?token=secretvalue&x=1");
        self::assertStringNotContainsString($token, $lines[0]);
        self::assertStringNotContainsString('secretvalue', $lines[0]);
        self::assertStringContainsString('x=1', $lines[0]);
    }
}
