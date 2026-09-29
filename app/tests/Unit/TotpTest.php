<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tutora\Auth\Base32;
use Tutora\Auth\Totp;

final class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B (SHA1 seed), truncated to the last 6 digits. */
    public static function rfcVectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
        yield [1234567890, '005924'];
        yield [2000000000, '279037'];
        yield [20000000000, '353130'];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code('12345678901234567890', Totp::stepAt($time)));
    }

    public function testVerifyAcceptsWindowAndRejectsReplay(): void
    {
        $secret = '12345678901234567890';
        $t = 1111111111;
        $step = Totp::stepAt($t);
        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $t, null));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $t, null));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $t, null));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step + 2), $t, null));
        // replay: same step already used
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step), $t, $step));
    }

    public function testVerifyRejectsMalformed(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', "123456\n"] as $bad) {
            self::assertNull(Totp::verify('12345678901234567890', $bad, 59, null));
        }
    }

    public function testBase32Rfc4648(): void
    {
        self::assertSame('MZXW6YTBOI', Base32::encode('foobar'));
        self::assertSame('foobar', Base32::decode('MZXW6YTBOI======'));
        $r = random_bytes(20);
        self::assertSame($r, Base32::decode(Base32::encode($r)));
        self::assertSame(32, strlen(Base32::encode(Totp::generateSecret())));
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri('foobar', 'a+b@example.org');
        self::assertStringStartsWith('otpauth://totp/Tutora:a%2Bb%40example.org?secret=MZXW6YTBOI&issuer=Tutora', $uri);
    }
}
