<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Security\Base64Url;
use Tutora\Security\HmacToken;
use Tutora\Support\FrozenClock;

final class HmacTokenTest extends TestCase
{
    private FrozenClock $clock;
    private HmacToken $relay;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->relay = new HmacToken(str_repeat("\x01", 32), HmacToken::AUD_RELAY, $this->clock);
    }

    public function testRoundTrip(): void
    {
        $t = $this->relay->issue(['session_id' => 5, 'actor_id' => 'abc', 'role' => 'participant'], 60);
        $claims = $this->relay->verify($t);
        self::assertNotNull($claims);
        self::assertSame(5, $claims['session_id']);
        self::assertSame('tutora-relay', $claims['aud']);
    }

    public function testExpired(): void
    {
        $t = $this->relay->issue(['session_id' => 5], 60);
        $this->clock->advance('PT60S');
        self::assertNull($this->relay->verify($t));
    }

    public function testWrongAudienceWithSameKeyRejected(): void
    {
        $wb = new HmacToken(str_repeat("\x01", 32), HmacToken::AUD_WHITEBOARD, $this->clock);
        self::assertNull($wb->verify($this->relay->issue(['session_id' => 5], 60)));
    }

    public function testWrongKeyRejected(): void
    {
        $other = new HmacToken(str_repeat("\x02", 32), HmacToken::AUD_RELAY, $this->clock);
        self::assertNull($other->verify($this->relay->issue(['session_id' => 5], 60)));
    }

    public function testTamperedPayloadRejected(): void
    {
        [$payload, $sig] = explode('.', $this->relay->issue(['session_id' => 5, 'role' => 'participant'], 60));
        $claims = json_decode(Base64Url::decode($payload), true);
        $claims['role'] = 'tutor';
        self::assertNull($this->relay->verify(Base64Url::encode(json_encode($claims)) . '.' . $sig));
    }

    public function testCallerCannotOverrideAudOrExp(): void
    {
        $t = $this->relay->issue(['aud' => 'tutora-whiteboard', 'exp' => PHP_INT_MAX], 60);
        $claims = $this->relay->verify($t);
        self::assertSame('tutora-relay', $claims['aud']);
        self::assertSame($this->clock->now()->getTimestamp() + 60, $claims['exp']);
    }

    public function testGarbageRejected(): void
    {
        foreach (['', '.', 'a.b', 'a.b.c', str_repeat('a', 5000)] as $g) {
            self::assertNull($this->relay->verify($g));
        }
    }

    public function testShortKeyRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HmacToken('short', HmacToken::AUD_RELAY, $this->clock);
    }
}
