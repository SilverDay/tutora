<?php

declare(strict_types=1);

namespace Tutora\Slides;

final class ConverterResult
{
    public function __construct(public readonly int $exitCode, public readonly bool $timedOut = false)
    {
    }
}
