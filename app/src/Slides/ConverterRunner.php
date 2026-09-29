<?php

declare(strict_types=1);

namespace Tutora\Slides;

interface ConverterRunner
{
    /**
     * Converts $inDir/source.{pdf,pptx} into $outDir/page-N.png.
     * Exit codes (converter/convert.sh): 0 ok, 2 invalid input, 3 too many pages, 4 failed.
     */
    public function run(string $inDir, string $outDir, int $timeoutSeconds, int $maxPages): ConverterResult;

    /** uid:gid the converter writes as (the output dir must be writable by it). */
    public function containerUser(): string;
}
