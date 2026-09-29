<?php

declare(strict_types=1);

namespace Tutora\Ai;

/**
 * Provider-neutral LLM interface (spec: `generateSummary(text[]) → string`). Deviation: the
 * result also carries token counts, which the spec's ai_usage / ai_quota records need.
 *
 * Implementations receive the already delimited prompt and must not log it (spec: no AI
 * prompts in ordinary logs). A real provider needs a DPA and an EU endpoint or SCCs
 * (owner decision 6) before it is configured.
 */
interface SummaryProvider
{
    /** @throws \RuntimeException on any provider failure (message must not contain content) */
    public function generateSummary(SummaryPrompt $prompt): AiSummary;
}
