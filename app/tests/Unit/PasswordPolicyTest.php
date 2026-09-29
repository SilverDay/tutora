<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Auth\BreachCheckUnavailable;
use Tutora\Auth\BreachedPasswordChecker;
use Tutora\Auth\HibpPasswordChecker;
use Tutora\Auth\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    public function testHibpSendsOnlyPrefixAndMatchesSuffix(): void
    {
        $sha1 = strtoupper(sha1('password123456'));
        $seen = null;
        $checker = new HibpPasswordChecker(static function (string $prefix) use (&$seen, $sha1): string {
            $seen = $prefix;
            return "0000000000000000000000000000000000A:0\r\n" . substr($sha1, 5) . ":42\r\n";
        });
        self::assertTrue($checker->isBreached('password123456'));
        self::assertSame(substr($sha1, 0, 5), $seen);
    }

    public function testHibpPaddingEntryWithZeroCountIsNotBreached(): void
    {
        $sha1 = strtoupper(sha1('some unique pass'));
        $checker = new HibpPasswordChecker(static fn () => substr($sha1, 5) . ":0\n");
        self::assertFalse($checker->isBreached('some unique pass'));
    }

    public function testHibpFailureBecomesUnavailable(): void
    {
        $checker = new HibpPasswordChecker(static fn () => throw new \RuntimeException('down'));
        $this->expectException(BreachCheckUnavailable::class);
        $checker->isBreached('x');
    }

    public function testLengthAndEmailRules(): void
    {
        $p = new PasswordPolicy(self::checker(false));
        self::assertNotEmpty($p->validate('short', 'a@example.org'));
        self::assertNotEmpty($p->validate(str_repeat('x', 129), 'a@example.org'));
        self::assertNotEmpty($p->validate('my-klaus-password-x', 'klaus@example.org'));
        self::assertSame([], $p->validate('correct horse battery', 'a@example.org'));
        // no composition rules: long all-lowercase passphrase is fine
        self::assertSame([], $p->validate('averylongpassphrase', 'a@example.org'));
    }

    public function testBreachedRejected(): void
    {
        self::assertNotEmpty((new PasswordPolicy(self::checker(true)))->validate('correct horse battery', 'a@example.org'));
    }

    public function testFailClosedByDefaultAndFailOpenConfigurable(): void
    {
        $down = new class implements BreachedPasswordChecker {
            public function isBreached(string $password): bool
            {
                throw new BreachCheckUnavailable('down');
            }
        };
        self::assertNotEmpty((new PasswordPolicy($down))->validate('correct horse battery', 'a@example.org'));
        self::assertSame([], (new PasswordPolicy($down, true))->validate('correct horse battery', 'a@example.org'));
    }

    public static function checker(bool $breached): BreachedPasswordChecker
    {
        return new class ($breached) implements BreachedPasswordChecker {
            public function __construct(private bool $b)
            {
            }

            public function isBreached(string $password): bool
            {
                return $this->b;
            }
        };
    }
}
