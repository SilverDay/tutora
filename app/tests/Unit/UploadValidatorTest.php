<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Slides\UploadValidator;
use Tutora\Support\ValidationException;

final class UploadValidatorTest extends TestCase
{
    private const FIX = __DIR__ . '/../fixtures/';

    private function tmp(string $bytes): string
    {
        $p = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($p, $bytes);
        return $p;
    }

    public function testAcceptsRealPdfAndPptx(): void
    {
        self::assertSame('pdf', UploadValidator::detect(self::FIX . 'sample.pdf', 'Deck.PDF', 1 << 20));
        self::assertSame('pptx', UploadValidator::detect(self::FIX . 'sample.pptx', 'deck.pptx', 1 << 20));
    }

    /** @return iterable<string,array{string,string}> */
    public static function rejected(): iterable
    {
        yield 'extension mismatch' => [(string) file_get_contents(__DIR__ . '/../fixtures/sample.pdf'), 'deck.pptx'];
        yield 'pdf renamed exe' => [(string) file_get_contents(__DIR__ . '/../fixtures/sample.pdf'), 'deck.exe'];
        yield 'html as pdf' => ['<html><script>alert(1)</script>', 'x.pdf'];
        yield 'plain zip as pptx' => ["PK\x03\x04" . str_repeat('x', 100) . "PK\x05\x06", 'x.pptx'];
        yield 'docx as pptx' => ["PK\x03\x04[Content_Types].xml word/document.xml PK\x05\x06", 'x.pptx'];
        yield 'empty' => ['', 'x.pdf'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testRejects(string $bytes, string $name): void
    {
        $this->expectException(ValidationException::class);
        UploadValidator::detect($this->tmp($bytes), $name, 1 << 20);
    }

    public function testSizeCap(): void
    {
        $this->expectException(ValidationException::class);
        UploadValidator::detect(self::FIX . 'sample.pptx', 'deck.pptx', 1000);
    }

    public function testDisplayName(): void
    {
        self::assertSame('passwd', UploadValidator::displayName('../../etc/passwd'));
        self::assertSame('evilgpj.pptx', UploadValidator::displayName("evil\u{202E}gpj.pptx"));
        self::assertSame('deck.pptx', UploadValidator::displayName('C:\\Users\\k\\deck.pptx'));
    }
}
