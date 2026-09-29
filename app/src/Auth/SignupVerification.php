<?php

declare(strict_types=1);

namespace Tutora\Auth;

use PDO;
use Tutora\Mail\MailException;
use Tutora\Mail\Mailer;
use Tutora\Mail\MailMessage;
use Tutora\Security\Base64Url;
use Tutora\Security\Logger;
use Tutora\Support\Clock;
use Tutora\Support\Time;

/**
 * Pending signups and verification mails (owner decision 4b).
 *
 * - every valid signup gets the same neutral response; existing accounts receive a neutral
 *   "you already have an account" notice instead of a link
 * - a signup becomes an account only when its link is opened AND the password chosen at
 *   signup is entered (prevents pre-hijacking via someone else's link)
 * - link token: 256-bit, stored as SHA-256, 24 h, carried in the URL fragment so it never
 *   reaches server access logs
 */
final class SignupVerification
{
    public const TOKEN_TTL = 86400;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly string $appBaseUrl,
    ) {
    }

    public function createPending(string $email, string $displayName, string $passwordHash): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare('DELETE FROM pending_signups WHERE expires_at < ?')->execute([Time::toDb($now)]);
        $token = Base64Url::encode(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO pending_signups (email, display_name, password_hash, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$email, $displayName, $passwordHash, hash('sha256', $token, true),
            Time::toDb($now->modify('+' . self::TOKEN_TTL . ' seconds')), Time::toDb($now)]);
        $link = rtrim($this->appBaseUrl, '/') . '/verify-email#t=' . $token;
        $this->deliver(new MailMessage($email, 'Confirm your Tutora account', <<<TXT
            Hello,

            please confirm your new Tutora tutor account by opening this link and entering
            the password you chose when signing up:

            {$link}

            The link is valid for 24 hours.

            If you did not just sign up for Tutora, ignore this email. Nobody can use this
            link without the password that was chosen at signup.
            TXT));
    }

    public function notifyExisting(string $email): void
    {
        $login = rtrim($this->appBaseUrl, '/') . '/login';
        $this->deliver(new MailMessage($email, 'Your Tutora account', <<<TXT
            Hello,

            someone (probably you) tried to create a Tutora account with this email address.
            You already have an account. You can sign in here:

            {$login}

            If this was not you, you can ignore this email. Your account has not been changed.
            TXT));
    }

    /**
     * Consumes a pending signup if the token is valid and the password matches.
     *
     * @return array{email:string, display_name:string, password_hash:string}|null
     */
    public function consume(string $token, string $password, PasswordHasher $hasher): ?array
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT * FROM pending_signups WHERE token_hash = ? AND expires_at > ?');
        $s->execute([hash('sha256', $token, true), Time::toDb($this->clock->now())]);
        $row = $s->fetch();
        if ($row === false || !$hasher->verify($password, (string) $row['password_hash'])) {
            return null;
        }
        // all pending signups for this address are void once one is used
        $this->pdo->prepare('DELETE FROM pending_signups WHERE email = ?')->execute([$row['email']]);
        return ['email' => $row['email'], 'display_name' => $row['display_name'], 'password_hash' => $row['password_hash']];
    }

    private function deliver(MailMessage $m): void
    {
        try {
            $this->mailer->send($m);
        } catch (MailException $e) {
            // neutral to the user either way; operators see the failure (no address/content logged)
            $this->logger->error('Signup mail could not be delivered', ['error' => $e->getMessage()]);
        }
    }
}
