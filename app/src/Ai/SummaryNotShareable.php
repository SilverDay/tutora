<?php

declare(strict_types=1);

namespace Tutora\Ai;

/** The summary is based on too few responses to be shared with the group (owner decision). */
final class SummaryNotShareable extends \RuntimeException
{
}
