<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Mail\MailException;
use Tutora\Mail\MailMessage;
use Tutora\Mail\SmtpMailer;

/**
 * Runs the SMTP client against a local fake server doing a real STARTTLS handshake with a
 * throwaway certificate.
 */
final class SmtpMailerTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/tutora-smtp-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        foreach (['good' => 'IP:127.0.0.1', 'wrong' => 'DNS:other.test'] as $name => $san) {
            $cmd = sprintf('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=tutora-test -addext %s -keyout %s -out %s 2>/dev/null',
                escapeshellarg('subjectAltName=' . $san), escapeshellarg(self::$dir . "/{$name}.key"), escapeshellarg(self::$dir . "/{$name}.crt"));
            exec($cmd, $o, $rc);
            if ($rc !== 0) {
                self::markTestSkipped('openssl CLI not available');
            }
        }
    }

    /** @return array{int, string, resource} port, report path, process */
    private function server(string $cert, string ...$flags): array
    {
        $port = random_int(20000, 60000);
        $report = self::$dir . '/report-' . bin2hex(random_bytes(3)) . '.json';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../Support/fake_smtp_server.php', (string) $port,
            self::$dir . "/{$cert}.crt", self::$dir . "/{$cert}.key", $report, ...$flags], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($report . '.ready'); $i++) {
            usleep(20_000);
        }
        return [$port, $report, $proc];
    }

    private function report(string $path, $proc): array
    {
        for ($i = 0; $i < 200 && !is_file($path); $i++) {
            usleep(20_000);
        }
        proc_close($proc);
        return json_decode((string) file_get_contents($path), true);
    }

    private function mailer(int $port, ?string $ca = 'good', string $user = 'mailer@tutora.test'): SmtpMailer
    {
        return new SmtpMailer('127.0.0.1', $port, $user, 's3cret-pw', 'noreply@tutora.test', 'Tutora Ü', 'tutora.test', 5,
            $ca === null ? null : self::$dir . "/{$ca}.crt");
    }

    public function testSendsOverStartTlsAndAuthenticatesOnlyAfterTls(): void
    {
        [$port, $report, $proc] = $this->server('good');
        $body = "Hello Klaus,\n.leading dot line\nÜmlaut and a very long line " . str_repeat('x', 200);
        $this->mailer($port)->send(new MailMessage('klaus@example.org', "Confirm \r\nBcc: evil@example.org", $body));
        $r = $this->report($report, $proc);

        self::assertTrue($r['tls']);
        $beforeTls = array_column(array_filter($r['commands'], static fn ($c) => !$c['tls']), 'line');
        self::assertSame(['EHLO tutora.test', 'STARTTLS'], $beforeTls, 'nothing but EHLO/STARTTLS in cleartext');
        self::assertSame(['user' => 'mailer@tutora.test', 'pass' => 's3cret-pw', 'tls' => true], $r['auth']);

        $data = $r['data'];
        self::assertStringContainsString("To: <klaus@example.org>\r\n", $data);
        self::assertStringContainsString('Subject: Confirm  Bcc: evil@example.org' . "\r\n", $data, 'CR/LF stripped: no header injection');
        self::assertDoesNotMatchRegularExpression('/^Bcc:/m', $data);
        self::assertStringContainsString('From: "=?UTF-8?B?', $data);
        self::assertStringContainsString("\r\n..leading dot line", $data, 'dot-stuffed');
        [, $encoded] = explode("\r\n\r\n", $data, 2);
        // the CRLF before the terminating "." belongs to the DATA framing
        self::assertSame(str_replace("\n", "\r\n", $body), quoted_printable_decode(str_replace("\r\n..", "\r\n.", substr($encoded, 0, -2))));
        foreach (explode("\r\n", $data) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
    }

    public function testRefusesServerWithoutStartTls(): void
    {
        [$port, $report, $proc] = $this->server('good', '--no-starttls');
        try {
            $this->mailer($port)->send(new MailMessage('klaus@example.org', 's', 'b'));
            self::fail('sent without TLS');
        } catch (MailException $e) {
            self::assertStringContainsString('STARTTLS', $e->getMessage());
        }
        $r = $this->report($report, $proc);
        self::assertNull($r['auth']);
        self::assertSame(['EHLO tutora.test'], array_column($r['commands'], 'line'), 'no credentials, sender or recipient sent');
    }

    public function testRejectsUntrustedCertificate(): void
    {
        [$port, $report, $proc] = $this->server('good');
        try {
            $this->mailer($port, null)->send(new MailMessage('klaus@example.org', 's', 'b'));
            self::fail('accepted untrusted certificate');
        } catch (MailException) {
        }
        $r = $this->report($report, $proc);
        self::assertNull($r['auth']);
        self::assertNull($r['data']);
    }

    public function testRejectsCertificateForAnotherHost(): void
    {
        [$port, $report, $proc] = $this->server('wrong');
        try {
            $this->mailer($port, 'wrong')->send(new MailMessage('klaus@example.org', 's', 'b'));
            self::fail('accepted certificate with wrong name');
        } catch (MailException) {
        }
        self::assertNull($this->report($report, $proc)['auth']);
    }

    public function testAuthLoginFallback(): void
    {
        [$port, $report, $proc] = $this->server('good', '--auth=LOGIN');
        $this->mailer($port)->send(new MailMessage('klaus@example.org', 's', 'b'));
        self::assertSame(['user' => 'mailer@tutora.test', 'pass' => 's3cret-pw', 'tls' => true], $this->report($report, $proc)['auth']);
    }

    public function testInvalidRecipientRejectedBeforeConnecting(): void
    {
        $this->expectException(MailException::class);
        $this->mailer(1)->send(new MailMessage("klaus@example.org>\r\nRCPT TO:<x@evil.example", 's', 'b'));
    }

    public function testErrorsNeverContainCredentials(): void
    {
        [$port, $report, $proc] = $this->server('good', '--auth=CRAM-MD5');
        try {
            $this->mailer($port)->send(new MailMessage('klaus@example.org', 's', 'b'));
            self::fail('expected failure');
        } catch (MailException $e) {
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
        $this->report($report, $proc);
    }
}
