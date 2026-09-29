<?php

declare(strict_types=1);

namespace Tutora\Block;

use Tutora\Support\Limits;
use Tutora\Support\ValidationException;

/**
 * Strict reader for tutor-supplied JSON block configs: unknown keys are rejected,
 * every value is type- and size-checked, and the normalised result contains only
 * known keys.
 */
final class ConfigReader
{
    /** @var list<string> */
    private array $errors = [];
    /** @var array<string,true> */
    private array $read = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data, private readonly string $context = 'config')
    {
    }

    public function string(string $key, int $max, bool $required = true, int $min = 1): ?string
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? null;
        if ($v === null && !$required) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            $this->errors[] = "{$this->context}.{$key} must be text.";
            return null;
        }
        $v = trim($v);
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min || $len > $max) {
            $this->errors[] = "{$this->context}.{$key} must be {$min}–{$max} characters.";
            return null;
        }
        return $v;
    }

    public function int(string $key, int $min, int $max, ?int $default = null): ?int
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            $this->errors[] = "{$this->context}.{$key} must be a whole number between {$min} and {$max}.";
            return null;
        }
        return $v;
    }

    public function number(string $key, float $min, float $max, bool $required = true): int|float|null
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? null;
        if ($v === null && !$required) {
            return null;
        }
        if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v < $min || $v > $max) {
            $this->errors[] = "{$this->context}.{$key} must be a number between {$min} and {$max}.";
            return null;
        }
        return $v;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? $default;
        if (!is_bool($v)) {
            $this->errors[] = "{$this->context}.{$key} must be true or false.";
            return null;
        }
        return $v;
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, ?string $default = null): ?string
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? $default;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[] = "{$this->context}.{$key} must be one of: " . implode(', ', $allowed) . '.';
            return null;
        }
        return $v;
    }

    /**
     * List of {id, label} items. Missing ids are assigned ("<prefix>1", ...).
     *
     * @return list<array{id:string,label:string}>
     */
    public function items(string $key, int $minCount, int $maxCount, string $idPrefix): array
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? null;
        if (!is_array($v) || !array_is_list($v) || count($v) < $minCount || count($v) > $maxCount) {
            $this->errors[] = "{$this->context}.{$key} must be a list of {$minCount}–{$maxCount} entries.";
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($v as $i => $item) {
            if (is_string($item)) {
                $item = ['label' => $item];
            }
            if (!is_array($item)) {
                $this->errors[] = "{$this->context}.{$key}[{$i}] is invalid.";
                continue;
            }
            $r = new self($item, "{$this->context}.{$key}[{$i}]");
            $id = $item['id'] ?? ($idPrefix . ($i + 1));
            $r->read['id'] = true;
            $label = $r->string('label', Limits::LABEL);
            $r->finish();
            array_push($this->errors, ...$r->errors);
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $id) !== 1 || isset($seen[$id])) {
                $this->errors[] = "{$this->context}.{$key}[{$i}].id must be unique (letters, digits, _ or -).";
                continue;
            }
            $seen[$id] = true;
            if ($label !== null) {
                $out[] = ['id' => $id, 'label' => $label];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> raw sub-objects for nested readers */
    public function objects(string $key, int $minCount, int $maxCount): array
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? null;
        if (!is_array($v) || !array_is_list($v) || count($v) < $minCount || count($v) > $maxCount) {
            $this->errors[] = "{$this->context}.{$key} must be a list of {$minCount}–{$maxCount} entries.";
            return [];
        }
        foreach ($v as $i => $o) {
            if (!is_array($o) || ($o !== [] && array_is_list($o))) {
                $this->errors[] = "{$this->context}.{$key}[{$i}] must be an object.";
                return [];
            }
        }
        return $v;
    }

    /** @return array<string,mixed>|null */
    public function object(string $key, bool $required = true): ?array
    {
        $this->read[$key] = true;
        $v = $this->data[$key] ?? null;
        if ($v === null && !$required) {
            return null;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->errors[] = "{$this->context}.{$key} must be an object.";
            return null;
        }
        return $v;
    }

    public function raw(string $key): mixed
    {
        $this->read[$key] = true;
        return $this->data[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    /** @param list<string> $errors */
    public function mergeErrors(array $errors): void
    {
        array_push($this->errors, ...$errors);
    }

    /** Records unknown keys as errors. */
    public function finish(): void
    {
        foreach (array_keys($this->data) as $k) {
            if (!isset($this->read[(string) $k])) {
                $this->errors[] = "{$this->context}.{$k} is not a recognised setting.";
            }
        }
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @throws ValidationException */
    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException(array_values(array_unique($this->errors)));
        }
    }
}
