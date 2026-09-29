<?php

declare(strict_types=1);

namespace Tutora\Security;

/**
 * Contextual output encoding (spec: "Output encoding — a global invariant").
 * All user-controlled text is stored as text and encoded at the rendering boundary.
 */
final class Html
{
    /** HTML body text and quoted attribute values. */
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** JSON for embedding inside <script type="application/json"> blocks. */
    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    /** Only http(s) and relative URLs; anything else (javascript:, data:) becomes '#'. */
    public static function url(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || preg_match('#^(https?://|/(?!/)|\./|\?|\#)#i', $trimmed) !== 1) {
            return '#';
        }
        return self::e($trimmed);
    }
}
