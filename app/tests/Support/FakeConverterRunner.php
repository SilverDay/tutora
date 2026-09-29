<?php

declare(strict_types=1);

namespace Tutora\Tests\Support;

use Tutora\Slides\ConverterResult;
use Tutora\Slides\ConverterRunner;

/** Test double: writes configurable (possibly hostile) output into the job's out dir. */
final class FakeConverterRunner implements ConverterRunner
{
    /** @var callable(string $in, string $out): ConverterResult */
    public $behaviour;
    public int $calls = 0;
    public ?string $seenSource = null;

    public function __construct(?callable $behaviour = null)
    {
        $this->behaviour = $behaviour ?? self::pages(2);
    }

    public static function pages(int $n): callable
    {
        return static function (string $in, string $out) use ($n): ConverterResult {
            for ($i = 1; $i <= $n; $i++) {
                copy(__DIR__ . '/../fixtures/page.png', "{$out}/page-{$i}.png");
            }
            return new ConverterResult(0);
        };
    }

    public function run(string $inDir, string $outDir, int $timeoutSeconds, int $maxPages): ConverterResult
    {
        $this->calls++;
        $this->seenSource = implode(',', array_diff(scandir($inDir) ?: [], ['.', '..']));
        return ($this->behaviour)($inDir, $outDir);
    }

    public function containerUser(): string
    {
        return '65532:65532';
    }
}
