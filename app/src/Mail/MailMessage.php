<?php

declare(strict_types=1);

namespace Tutora\Mail;

/** A plain-text email (no HTML: nothing user-supplied can become markup in a mail client). */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $textBody,
    ) {
    }
}
