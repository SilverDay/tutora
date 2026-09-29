<?php

declare(strict_types=1);

namespace Tutora\Support;

/**
 * Normalisation for user-supplied free text before storage. Text is still output-encoded
 * at every rendering boundary; this only enforces validity and size.
 */
final class Text
{
    /**
     * @return string|null cleaned text, or null if invalid / empty / too long
     */
    public static function clean(mixed $value, int $maxChars, bool $multiline = false): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        // strip control characters (keep \n and \t for multi-line text) and bidi overrides,
        // which could be used to visually spoof other participants' text
        $pattern = $multiline ? '/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u' : '/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';
        $value = (string) preg_replace($pattern, '', $multiline ? str_replace("\r\n", "\n", $value) : $value);
        $value = trim($multiline ? $value : (string) preg_replace('/\s+/u', ' ', $value));
        $len = mb_strlen($value, 'UTF-8');
        return $len === 0 || $len > $maxChars ? null : $value;
    }
}
