<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tutora\Export\CsvWriter;

final class CsvWriterTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function dangerous(): iterable
    {
        yield 'equals' => ['=HYPERLINK("http://evil.example","x")', '"\'=HYPERLINK(""http://evil.example"",""x"")"'];
        yield 'plus' => ['+1+1', '"\'+1+1"'];
        yield 'minus' => ['-2+3', '"\'-2+3"'];
        yield 'at' => ['@SUM(A1)', '"\'@SUM(A1)"'];
        yield 'tab' => ["\t=1", "\"'\t=1\""];
        yield 'carriage return' => ["\r=1", "\"'\r=1\""];
        yield 'leading spaces' => ['   =cmd|"/c calc"!A1', '"\'   =cmd|""/c calc""!A1"'];
        yield 'full-width equals' => ['＝1+1', '"\'＝1+1"'];
    }

    #[DataProvider('dangerous')]
    public function testFormulaTriggersAreNeutralised(string $in, string $expected): void
    {
        self::assertSame($expected, CsvWriter::field($in));
    }

    public function testOrdinaryValues(): void
    {
        self::assertSame('"Great pace, clear"', CsvWriter::field('Great pace, clear'));
        self::assertSame("\"line1\nline2\"", CsvWriter::field("line1\nline2"), 'newlines kept inside quotes');
        self::assertSame('"a ""quote"""', CsvWriter::field('a "quote"'));
        self::assertSame('"-5"', CsvWriter::field(-5), 'numbers we produce stay numbers');
        self::assertSame('"2.5"', CsvWriter::field(2.5));
        self::assertSame('"true"', CsvWriter::field(true));
        self::assertSame('""', CsvWriter::field(null));
        self::assertSame('"ab"', CsvWriter::field("a\x00\x1Bb"), 'control characters removed');
        self::assertSame('"e-mail = fine"', CsvWriter::field('e-mail = fine'), 'only the first character matters');
    }

    public function testRowsHaveBomAndCrlf(): void
    {
        $w = new CsvWriter();
        $w->row(['a', 1]);
        self::assertSame("\u{FEFF}\"a\",\"1\"\r\n", $w->contents());
    }
}
