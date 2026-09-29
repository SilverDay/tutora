<?php

declare(strict_types=1);

namespace Tutora\Security;

/**
 * Application logger that redacts sensitive data before writing
 * (spec: "No sensitive-data logging" — resume tokens, connection tokens,
 * participant submissions, AI prompts must not reach ordinary logs).
 *
 * Context values under sensitive keys are replaced wholesale; free-text messages are
 * additionally scrubbed of anything that looks like one of our tokens.
 */
final class Logger
{
    private const SENSITIVE_KEYS = [
        'token', 'credential', 'password', 'totp', 'mfa', 'otp', 'secret', 'payload', 'text',
        'words', 'prompt', 'submission', 'submissions', 'answer', 'cookie', 'authorization', 'csrf',
    ];

    /** @var callable(string):void */
    private $sink;

    /** @param (callable(string):void)|null $sink */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static fn (string $line) => error_log($line);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            '[%s] %s %s',
            $level,
            self::scrub($message),
            $context === [] ? '' : json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
        ($this->sink)(rtrim($line));
    }

    /**
     * @param array<array-key,mixed> $context
     * @return array<array-key,mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            if (is_string($k) && self::isSensitiveKey($k)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $out[$k] = self::scrub($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public static function scrub(string $s): string
    {
        // HMAC tokens (base64url.base64url with 43-char signature)
        $s = (string) preg_replace('/[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{43}\b/', '[REDACTED-TOKEN]', $s);
        // query-string style secrets
        $s = (string) preg_replace('/\b(token|resume|credential|code|password|secret)=([^&\s]+)/i', '$1=[REDACTED]', $s);
        return $s;
    }

    private static function isSensitiveKey(string $key): bool
    {
        // match whole key segments (resume_token, answer_payload) not substrings (context)
        $segments = preg_split('/[_\-.]+/', strtolower($key)) ?: [];
        return array_intersect($segments, self::SENSITIVE_KEYS) !== [];
    }
}
