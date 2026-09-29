<?php

declare(strict_types=1);

namespace Tutora\Mail;

/** Delivery failed. The message never contains credentials or mail content. */
final class MailException extends \RuntimeException
{
}
