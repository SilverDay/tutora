<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Config;

final class ConfigTest extends TestCase
{
    public function testParsesEnvFileWithoutInterpolation(): void
    {
        $vals = Config::parseEnvFile("# comment\nA=1\nB=\"quoted value\"\nC=\$(whoami)\nlower=x\n\nD='single'");
        self::assertSame(['A' => '1', 'B' => 'quoted value', 'C' => '$(whoami)', 'D' => 'single'], $vals);
    }

    public function testMissingRequiredValueThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::fromArray([])->string('DB_NAME');
    }

    public function testKeyRequires32ByteHex(): void
    {
        $c = Config::fromArray(['K' => str_repeat('ab', 32), 'SHORT' => 'abcd']);
        self::assertSame(32, strlen($c->key('K')));
        $this->expectException(\RuntimeException::class);
        $c->key('SHORT');
    }

    public function testBoolAndList(): void
    {
        $c = Config::fromArray(['B' => 'false', 'L' => ' a, b ,,c ']);
        self::assertFalse($c->bool('B', true));
        self::assertTrue($c->bool('MISSING', true));
        self::assertSame(['a', 'b', 'c'], $c->list('L'));
    }

    public function testDefaultsToProduction(): void
    {
        self::assertTrue(Config::fromArray([])->isProduction());
    }
}
