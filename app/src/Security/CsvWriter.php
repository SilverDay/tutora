<?php

declare(strict_types=1);

namespace Tutora\Security;

/**
 * CSV writer with formula-injection protection (spec: Additional Hardening).
 * Cells starting with = + - @ TAB CR (or those after leading whitespace) are prefixed with
 * a single quote so spreadsheet applications treat them as text.
 */
final class CsvWriter
{
    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    /** @param list<string|int|float|bool|null> $row */
    public function write(array $row): void
    {
        fputcsv($this->stream, array_map(self::neutralize(...), $row), ',', '"', '');
    }

    public static function neutralize(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value; // numbers are safe as-is (a negative number is not a formula)
        }
        if ($value !== '' && preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }
}
