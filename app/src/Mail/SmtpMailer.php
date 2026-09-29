<?php

declare(strict_types=1);

namespace Tutora\Mail;

use Tutora\Support\Clock;
use Tutora\Support\SystemClock;

/**
 * Minimal SMTP submission client (RFC 6409, port 587) with mandatory STARTTLS (RFC 3207).
 *
 * - refuses to continue if the server does not offer STARTTLS: no credentials, sender,
 *   recipient or content is ever sent in cleartext
 * - verifies the server certificate and host name (TLS 1.2+)
 * - authenticates (AUTH PLAIN, else LOGIN) only after TLS is established
 * - never logs or includes credentials or message content in errors
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly string $heloName,
        private readonly int $timeout = 15,
        private readonly ?string $caFile = null,
        private readonly Clock $clock = new SystemClock(),
    ) {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SMTP host/port not configured');
        }
        MimeFormatter::address($from);
    }

    public function send(MailMessage $message): void
    {
        $to = MimeFormatter::address($message->to);
        $data = MimeFormatter::format($message, $this->from, $this->fromName, $this->heloName, $this->clock->now());
        try {
            $this->connect();
            $this->expect(220);
            $caps = $this->ehlo();
            if (!in_array('STARTTLS', $caps, true)) {
                throw new MailException('SMTP server does not offer STARTTLS; refusing to send');
            }
            $this->command('STARTTLS', 220);
            $ok = @stream_socket_enable_crypto($this->socket(), true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if ($ok !== true) {
                throw new MailException('TLS negotiation with the SMTP server failed');
            }
            $caps = $this->ehlo();
            if ($this->username !== '') {
                $this->authenticate($caps);
            }
            $this->command('MAIL FROM:<' . $this->from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', 354);
            // dot-stuffing (RFC 5321 4.5.2)
            $stuffed = (string) preg_replace('/^\./m', '..', $data);
            $this->write($stuffed . "\r\n.\r\n");
            $this->expect(250);
            $this->write("QUIT\r\n");
        } finally {
            if (is_resource($this->sock)) {
                fclose($this->sock);
            }
            $this->sock = null;
        }
    }

    private function connect(): void
    {
        $ctx = stream_context_create(['ssl' => array_filter([
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'cafile' => $this->caFile,
        ], static fn ($v) => $v !== null)]);
        $sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($sock)) {
            throw new MailException('Cannot connect to the SMTP server');
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
    }

    /** @return list<string> upper-cased capability keywords */
    private function ehlo(): array
    {
        $this->authMechanisms = [];
        $this->write('EHLO ' . $this->heloName . "\r\n");
        [$code, $lines] = $this->read();
        if ($code !== 250) {
            throw new MailException("SMTP EHLO rejected ({$code})");
        }
        return array_map(static fn ($l) => strtoupper(strtok($l, ' ') ?: ''), array_slice($lines, 1));
    }

    /** @param list<string> $caps */
    private function authenticate(array $caps): void
    {
        // mechanisms come from the post-TLS EHLO (collected in read())
        if (!in_array('AUTH', $caps, true)) {
            throw new MailException('SMTP server does not offer authentication');
        }
        if (in_array('PLAIN', $this->authMechanisms, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), 235, true);
        } elseif (in_array('LOGIN', $this->authMechanisms, true)) {
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->username), 334, true);
            $this->command(base64_encode($this->password), 235, true);
        } else {
            throw new MailException('SMTP server offers no supported authentication mechanism');
        }
    }

    /** @var list<string> AUTH mechanisms advertised in the most recent EHLO */
    private array $authMechanisms = [];

    /** @param int|list<int> $expected */
    private function command(string $line, int|array $expected, bool $sensitive = false): void
    {
        $this->write($line . "\r\n");
        [$code] = $this->read();
        if (!in_array($code, (array) $expected, true)) {
            $verb = $sensitive ? 'AUTH' : strtok($line, ' :');
            throw new MailException("SMTP command {$verb} rejected ({$code})");
        }
    }

    private function expect(int $code): void
    {
        [$got] = $this->read();
        if ($got !== $code) {
            throw new MailException("Unexpected SMTP reply ({$got})");
        }
    }

    /** @return array{int, list<string>} */
    private function read(): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($this->socket(), 2048);
            if ($line === false) {
                throw new MailException('SMTP connection closed or timed out');
            }
            $line = rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            $text = substr($line, 4);
            $lines[] = $text;
            if (preg_match('/^AUTH[ =](.*)$/i', $text, $m) === 1) {
                $this->authMechanisms = array_values(array_filter(array_map('strtoupper', preg_split('/\s+/', trim($m[1])) ?: [])));
            }
            if (($line[3] ?? ' ') !== '-') {
                return [$code, $lines];
            }
        }
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        for ($written = 0; $written < $len; $written += $n) {
            $n = fwrite($this->socket(), substr($data, $written));
            if ($n === false || $n === 0) {
                throw new MailException('Writing to the SMTP server failed');
            }
        }
    }

    /** @return resource */
    private function socket()
    {
        return $this->sock ?? throw new MailException('SMTP connection is not open');
    }
}
