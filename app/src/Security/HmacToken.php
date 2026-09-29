<?php

declare(strict_types=1);

namespace Tutora\Security;

use Tutora\Support\Clock;

/**
 * Compact self-verifying token: base64url(json claims) "." base64url(HMAC-SHA256).
 *
 * Used for relay connection tokens (aud=tutora-relay), whiteboard tokens
 * (aud=tutora-whiteboard) and participant credentials (aud=tutora-participant).
 * Each audience has its own key; `aud` and `exp` are always required and verified.
 * The same format is verified by the Go relay and the Node sidecar.
 */
final class HmacToken
{
    public const AUD_RELAY = 'tutora-relay';
    public const AUD_WHITEBOARD = 'tutora-whiteboard';
    public const AUD_PARTICIPANT = 'tutora-participant';

    public function __construct(
        private readonly string $key,
        private readonly string $audience,
        private readonly Clock $clock,
    ) {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('HMAC key must be at least 32 bytes');
        }
    }

    /** @param array<string,scalar|list<string>> $claims */
    public function issue(array $claims, int $ttlSeconds): string
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('TTL must be positive');
        }
        $claims['aud'] = $this->audience;
        $claims['exp'] = $this->clock->now()->getTimestamp() + $ttlSeconds;
        $payload = Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $payload . '.' . Base64Url::encode(hash_hmac('sha256', $payload, $this->key, true));
    }

    /**
     * @return array<string,mixed>|null claims, or null if invalid/expired/wrong audience
     */
    public function verify(string $token): ?array
    {
        if (strlen($token) > 4096) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        try {
            $given = Base64Url::decode($sig);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $payload, $this->key, true), $given)) {
            return null;
        }
        try {
            $claims = json_decode(Base64Url::decode($payload), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
        if (!is_array($claims)
            || ($claims['aud'] ?? null) !== $this->audience
            || !is_int($claims['exp'] ?? null)
            || $claims['exp'] <= $this->clock->now()->getTimestamp()) {
            return null;
        }
        return $claims;
    }
}
