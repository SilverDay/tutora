<?php

declare(strict_types=1);

namespace Tutora\Ai;

/**
 * Deterministic offline provider for development and tests (owner decision 6). It never
 * sends data anywhere. Refused in production by the App wiring.
 */
final class StubSummaryProvider implements SummaryProvider
{
    /** @var list<SummaryPrompt> prompts received (tests) */
    public array $prompts = [];

    public function __construct(private readonly ?string $fixedOutput = null, private readonly bool $fail = false)
    {
    }

    public function generateSummary(SummaryPrompt $prompt): AiSummary
    {
        $this->prompts[] = $prompt;
        if ($this->fail) {
            throw new \RuntimeException('stub provider failure');
        }
        $text = $this->fixedOutput ?? self::summary($prompt->responses);
        return new AiSummary($text, intdiv(strlen($prompt->system . $prompt->user), 4) + 1, intdiv(strlen($text), 4) + 1);
    }

    /** @param list<string> $responses */
    private static function summary(array $responses): string
    {
        $counts = [];
        foreach ($responses as $r) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($r, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                if (mb_strlen($w, 'UTF-8') >= 4) {
                    $counts[$w] = ($counts[$w] ?? 0) + 1;
                }
            }
        }
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, 5);
        return '- ' . count($responses) . ' responses (offline stub summary)' . ($top === [] ? '' : "\n- Frequent words: " . implode(', ', $top));
    }
}
