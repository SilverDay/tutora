<?php

declare(strict_types=1);

namespace Tutora;

use RuntimeException;

/**
 * Immutable application configuration read from environment variables
 * (optionally populated from a .env file; real environment wins).
 */
final class Config
{
    /** @param array<string,string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $values = [];
        if ($envFile !== null && is_readable($envFile)) {
            $values = self::parseEnvFile((string) file_get_contents($envFile));
        }
        foreach (getenv() as $key => $value) {
            $values[$key] = $value;
        }
        return new self($values);
    }

    /** @param array<string,string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Minimal KEY=VALUE parser: no interpolation, no command substitution.
     *
     * @return array<string,string>
     */
    public static function parseEnvFile(string $contents): array
    {
        $out = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            $out[$key] = $val;
        }
        return $out;
    }

    public function string(string $key, ?string $default = null): string
    {
        $v = $this->values[$key] ?? null;
        if ($v === null || $v === '') {
            if ($default === null) {
                throw new RuntimeException("Missing required configuration value: {$key}");
            }
            return $default;
        }
        return $v;
    }

    public function int(string $key, ?int $default = null): int
    {
        $v = $this->string($key, $default === null ? null : (string) $default);
        if (!preg_match('/^-?\d+$/', $v)) {
            throw new RuntimeException("Configuration value {$key} must be an integer");
        }
        return (int) $v;
    }

    public function bool(string $key, bool $default): bool
    {
        $v = strtolower($this->string($key, $default ? 'true' : 'false'));
        return match ($v) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new RuntimeException("Configuration value {$key} must be boolean"),
        };
    }

    /** @return list<string> */
    public function list(string $key, string $default = ''): array
    {
        $raw = $this->string($key, $default);
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
    }

    /**
     * 32-byte binary key from a 64-char hex value. Refuses weak/short keys.
     */
    public function key(string $key): string
    {
        $hex = $this->string($key);
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new RuntimeException("Configuration value {$key} must be 64 hex characters (32 bytes)");
        }
        return (string) hex2bin($hex);
    }

    public function isProduction(): bool
    {
        return $this->string('APP_ENV', 'production') === 'production';
    }
}
