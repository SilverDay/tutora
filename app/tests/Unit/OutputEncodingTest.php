<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Security\CsvWriter;
use Tutora\Security\Html;

final class OutputEncodingTest extends TestCase
{
    public function testHtmlEscapesMarkupAndQuotes(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &apos;', Html::e('<script>alert("x")</script> \''));
    }

    public function testHtmlSubstitutesInvalidUtf8(): void
    {
        self::assertNotSame('', Html::e("a\xB1b"));
    }

    public function testJsonForScriptBlockCannotBreakOut(): void
    {
        $out = Html::json(['t' => '</script><script>alert(1)</script>']);
        self::assertStringNotContainsString('</script>', $out);
        self::assertStringNotContainsString('<', $out);
    }

    public function testUrlRejectsDangerousSchemes(): void
    {
        self::assertSame('#', Html::url('javascript:alert(1)'));
        self::assertSame('#', Html::url(' JaVaScRiPt:alert(1)'));
        self::assertSame('#', Html::url('data:text/html,x'));
        self::assertSame('#', Html::url('//evil.example'));
        self::assertSame('/ok?a=1&amp;b=2', Html::url('/ok?a=1&b=2'));
        self::assertSame('https://example.org/', Html::url('https://example.org/'));
    }

    /** @return iterable<array{string,string}> */
    public static function formulaCases(): iterable
    {
        yield ['=1+1', "'=1+1"];
        yield ['+SUM(A1)', "'+SUM(A1)"];
        yield ['-2+3', "'-2+3"];
        yield ['@cmd', "'@cmd"];
        yield ["\t=x", "'\t=x"];
        yield ["\r=x", "'\r=x"];
        yield ['  =HYPERLINK("x")', "'  =HYPERLINK(\"x\")"];
        yield ['hello', 'hello'];
        yield ['a=b', 'a=b'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formulaCases')]
    public function testCsvNeutralizesFormulas(string $in, string $expected): void
    {
        self::assertSame($expected, CsvWriter::neutralize($in));
    }

    public function testCsvNumbersUntouched(): void
    {
        self::assertSame('-5', CsvWriter::neutralize(-5));
        self::assertSame('', CsvWriter::neutralize(null));
    }

    public function testCsvWriterOutput(): void
    {
        $fh = fopen('php://memory', 'w+');
        (new CsvWriter($fh))->write(['=cmd|"/c calc"!A1', 'plain', 3]);
        rewind($fh);
        self::assertSame("\"'=cmd|\"\"/c calc\"\"!A1\",plain,3\n", stream_get_contents($fh));
    }
}
