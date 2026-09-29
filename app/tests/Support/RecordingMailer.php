<?php

declare(strict_types=1);

namespace Tutora\Tests\Support;

use Tutora\Mail\Mailer;
use Tutora\Mail\MailException;
use Tutora\Mail\MailMessage;

final class RecordingMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $sent = [];
    public bool $fail = false;

    public function send(MailMessage $message): void
    {
        if ($this->fail) {
            throw new MailException('simulated failure');
        }
        $this->sent[] = $message;
    }

    /** Token from the most recent verification link sent to $email. */
    public function tokenFor(string $email): ?string
    {
        foreach (array_reverse($this->sent) as $m) {
            if ($m->to === $email && preg_match('/#t=([A-Za-z0-9_-]+)/', $m->textBody, $x) === 1) {
                return $x[1];
            }
        }
        return null;
    }
}
