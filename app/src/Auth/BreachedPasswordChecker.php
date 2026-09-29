<?php

declare(strict_types=1);

namespace Tutora\Auth;

interface BreachedPasswordChecker
{
    /** @throws BreachCheckUnavailable when the check cannot be performed */
    public function isBreached(string $password): bool;
}
