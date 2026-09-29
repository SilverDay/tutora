<?php

declare(strict_types=1);

namespace Tutora\Auth;

/** RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30 s step) — the profile all common authenticator apps support. */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** accepted clock drift in steps either side */
    public const WINDOW = 1;

    public static function generateSecret(): string
    {
        return random_bytes(20); // 160 bits, RFC 4226 recommendation
    }

    public static function code(string $secret, int $step): string
    {
        $mac = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($mac[19]) & 0x0f;
        $bin = ((ord($mac[$offset]) & 0x7f) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);
        return str_pad((string) ($bin % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    /**
     * @return int|null the matched time step, or null. Steps <= $lastUsedStep are rejected (replay protection).
     */
    public static function verify(string $secret, string $code, int $unixTime, ?int $lastUsedStep): ?int
    {
        $code = str_replace(' ', '', $code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/D', $code) !== 1) {
            return null;
        }
        $now = self::stepAt($unixTime);
        $matched = null;
        // check all candidate steps (no early exit) to keep timing uniform
        for ($s = $now - self::WINDOW; $s <= $now + self::WINDOW; $s++) {
            if (hash_equals(self::code($secret, $s), $code) && ($lastUsedStep === null || $s > $lastUsedStep)) {
                $matched ??= $s;
            }
        }
        return $matched;
    }

    public static function provisioningUri(string $secret, string $accountEmail, string $issuer = 'Tutora'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountEmail),
            Base32::encode($secret),
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }
}
