<?php

declare(strict_types=1);

namespace Tutora\Security;

final class Base64Url
{
    public static function encode(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** @throws \InvalidArgumentException on malformed input */
    public static function decode(string $s): string
    {
        try {
            return sodium_base642bin($s, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $e) {
            throw new \InvalidArgumentException('Malformed base64url', 0, $e);
        }
    }
}
