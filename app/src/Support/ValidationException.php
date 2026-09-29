<?php

declare(strict_types=1);

namespace Tutora\Support;

/** Input failed validation; messages are safe to show to the user. */
final class ValidationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
