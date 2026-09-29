<?php

declare(strict_types=1);

namespace Tutora\Controller;

/** Carries a key of SessionController::ERRORS for redirect-after-POST feedback. */
final class ActionFailed extends \RuntimeException
{
}
