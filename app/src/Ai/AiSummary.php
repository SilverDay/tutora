<?php

declare(strict_types=1);

namespace Tutora\Ai;

final class AiSummary
{
    public function __construct(
        public readonly string $text,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {
    }
}
