<?php

declare(strict_types=1);

namespace Tutora\Mail;

/**
 * Builds RFC 5322 plain-text messages. Header values are validated/encoded so user input
 * can never inject headers; the body is quoted-printable (safe for UTF-8 and long lines).
 */
final class MimeFormatter
{
    public static function address(string $email): string
    {
        if (preg_match('/[\r\n\0<>]/', $email) === 1 || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('Invalid email address');
        }
        return $email;
    }

    /** RFC 2047 encoded-word for non-ASCII header text; strips CR/LF. */
    public static function encodeHeader(string $text): string
    {
        $text = (string) preg_replace('/[\r\n\0]+/', ' ', $text);
        if (preg_match('/^[\x20-\x7E]*$/', $text) === 1) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    public static function format(MailMessage $m, string $from, string $fromName, string $messageIdDomain, \DateTimeImmutable $now): string
    {
        $headers = [
            'Date' => $now->format(DATE_RFC2822),
            'From' => ($fromName === '' ? '' : '"' . str_replace(['"', '\\'], '', self::encodeHeader($fromName)) . '" ') . '<' . self::address($from) . '>',
            'To' => '<' . self::address($m->to) . '>',
            'Subject' => self::encodeHeader($m->subject),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $messageIdDomain) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
            'Auto-Submitted' => 'auto-generated',
        ];
        $out = '';
        foreach ($headers as $k => $v) {
            $out .= $k . ': ' . $v . "\r\n";
        }
        // normalise to CRLF; quoted_printable_encode keeps CRLF hard breaks and adds "=\r\n" soft breaks
        $body = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $m->textBody));
        return $out . "\r\n" . quoted_printable_encode($body);
    }
}
