<?php

declare(strict_types=1);

namespace Tutora\Activity;

/** The answer is well-formed but not acceptable now (closed, expired, duplicate). Message is user-safe. */
final class AnswerRejected extends \RuntimeException
{
}
