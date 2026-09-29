<?php

declare(strict_types=1);

namespace Tutora\Mail;

use Tutora\Support\Clock;
use Tutora\Support\SystemClock;

/**
 * Development-only mail driver: writes each message as an .eml file into an outbox
 * directory (used by local browser tests). Refused in production.
 */
final class FileMailer implements Mailer
{
    public function __construct(
        private readonly string $outboxDir,
        private readonly string $from,
        bool $production,
        private readonly Clock $clock = new SystemClock(),
    ) {
        if ($production) {
            throw new \LogicException('The file mail driver is not allowed in production');
        }
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->outboxDir) && !mkdir($this->outboxDir, 0750, true) && !is_dir($this->outboxDir)) {
            throw new MailException('Cannot create mail outbox');
        }
        $name = $this->clock->now()->format('Ymd\THis') . '-' . bin2hex(random_bytes(4)) . '.eml';
        file_put_contents($this->outboxDir . '/' . $name, MimeFormatter::format($message, $this->from, 'Tutora', 'localhost', $this->clock->now()));
    }
}
