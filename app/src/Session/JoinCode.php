<?php

declare(strict_types=1);

namespace Tutora\Session;

/**
 * 6-character join codes from an unambiguous alphabet (no 0/O, 1/I/L, U/V).
 * 29 symbols, 29^6 ≈ 5.9e8 codes; uniqueness among live sessions is enforced by the DB, guessing is
 * mitigated by rate limiting the join endpoint (spec: Auth & Multi-Tenancy).
 */
final class JoinCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTWXYZ23456789';
    public const LENGTH = 6;

    public static function generate(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Canonical form of user input, or null if it cannot be a valid code. */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');
        if (strlen($code) !== self::LENGTH || strspn($code, self::ALPHABET) !== self::LENGTH) {
            return null;
        }
        return $code;
    }
}
