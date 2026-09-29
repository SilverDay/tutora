<?php

declare(strict_types=1);

namespace Tutora\Activity;

/** The block is not the session's current block, the session is not live, or the block type does not accept this input. */
final class BlockNotOpen extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This activity is not open for input.');
    }
}
