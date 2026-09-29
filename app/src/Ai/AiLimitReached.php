<?php

declare(strict_types=1);

namespace Tutora\Ai;

/** A quota or the per-session daily call cap is exhausted (message is a fixed, user-safe code). */
final class AiLimitReached extends \RuntimeException
{
}
