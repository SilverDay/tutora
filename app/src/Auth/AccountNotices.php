<?php

declare(strict_types=1);

namespace Tutora\Auth;

use Tutora\Mail\MailException;
use Tutora\Mail\Mailer;
use Tutora\Mail\MailMessage;
use Tutora\Security\Logger;

/** Security notifications to tutors (best effort; failures are logged, never shown). */
final class AccountNotices
{
    public function __construct(private readonly Mailer $mailer, private readonly Logger $logger, private readonly string $appBaseUrl)
    {
    }

    public function recoveryCodeUsed(string $email, int $remaining): void
    {
        $this->send($email, 'A Tutora recovery code was used', <<<TXT
            Hello,

            a recovery code was just used to sign in to your Tutora account instead of your
            authenticator app. {$remaining} unused recovery code(s) remain.

            If this was you, consider generating new codes in your account settings.
            If this was not you, contact your Tutora administrator immediately.
            TXT);
    }

    public function mfaReset(string $email): void
    {
        $login = rtrim($this->appBaseUrl, '/') . '/login';
        $this->send($email, 'Your Tutora two-factor authentication was reset', <<<TXT
            Hello,

            an administrator has reset two-factor authentication for your Tutora account.
            At your next sign-in you will be asked to set up your authenticator app again:

            {$login}

            If you did not request this, contact your Tutora administrator immediately.
            TXT);
    }

    private function send(string $email, string $subject, string $body): void
    {
        try {
            $this->mailer->send(new MailMessage($email, $subject, $body));
        } catch (MailException $e) {
            $this->logger->error('Security notice could not be delivered', ['error' => $e->getMessage()]);
        }
    }
}
