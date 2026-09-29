<?php

declare(strict_types=1);

namespace Tutora\Security;

/** Authenticated symmetric encryption for secrets at rest (e.g. TOTP seeds). */
final class SecretBox
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('SecretBox key must be 32 bytes');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    public function decrypt(string $ciphertext): string
    {
        $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plain;
    }
}
