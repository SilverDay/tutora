<?php

declare(strict_types=1);

namespace Tutora\Support;

/**
 * Explicit maximum sizes for user-controlled content (spec: Additional Hardening).
 * Values are the proposed defaults recorded in docs/IMPLEMENTATION_PLAN.md §6.
 */
final class Limits
{
    public const DISPLAY_NAME = 40;
    public const WALL_CARD = 500;
    public const WRITE_RESPONSE = 2000;
    public const WORD_CLOUD_WORD = 40;
    public const WORKSHOP_TITLE = 200;
    public const WORKSHOP_DESCRIPTION = 5000;
    public const PROMPT = 1000;
    public const LABEL = 200;
    public const MAX_OPTIONS = 20;
    public const MAX_ITEMS = 50;
    public const MAX_QUIZ_QUESTIONS = 100;
    public const MAX_BLOCKS_PER_WORKSHOP = 200;
    public const MAX_CONFIG_BYTES = 65536;
    public const MAX_SUBMISSION_BYTES = 16384;
}
