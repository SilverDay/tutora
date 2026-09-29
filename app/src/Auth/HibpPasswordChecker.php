<?php

declare(strict_types=1);

namespace Tutora\Auth;

/**
 * Have I Been Pwned "Pwned Passwords" k-anonymity range API.
 * Only the first 5 hex chars of the SHA-1 leave this host; Add-Padding hides the
 * real response size. The password and its full hash are never sent or logged.
 */
final class HibpPasswordChecker implements BreachedPasswordChecker
{
    /** @var callable(string):string */
    private $fetch;

    /**
     * @param (callable(string):string)|null $fetch prefix => response body; throws on failure (test seam)
     */
    public function __construct(?callable $fetch = null, private readonly int $timeoutSeconds = 4)
    {
        $this->fetch = $fetch ?? $this->curlFetch(...);
    }

    public function isBreached(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $body = ($this->fetch)($prefix);
        } catch (\Throwable $e) {
            throw new BreachCheckUnavailable('Breached-password service unavailable', 0, $e);
        }

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) === 2 && hash_equals($parts[0], $suffix)) {
                // padding entries have count 0
                return (int) $parts[1] > 0;
            }
        }
        return false;
    }

    private function curlFetch(string $prefix): string
    {
        $ch = curl_init('https://api.pwnedpasswords.com/range/' . $prefix);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Add-Padding: true', 'User-Agent: Tutora-password-check'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            throw new \RuntimeException('HIBP request failed with status ' . $status);
        }
        return $body;
    }
}
