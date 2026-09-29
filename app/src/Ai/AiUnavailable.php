<?php

declare(strict_types=1);

namespace Tutora\Ai;

/** AI is not configured, or the provider call failed (message is a fixed, user-safe code). */
final class AiUnavailable extends \RuntimeException
{
}
