<?php

declare(strict_types=1);

namespace Tutora\Export;

/**
 * RFC 4180 CSV with formula-injection protection (spec: CSV/formula-injection protection on
 * any export containing participant free text; OWASP "CSV Injection").
 *
 * Every field is quoted. A cell whose first character is one a spreadsheet may evaluate
 * (= + - @, tab, carriage return — also after leading whitespace, and their full-width
 * forms) is prefixed with a single quote so it is shown as text. Leading/embedded control
 * characters other than newline and tab are removed. The output starts with a UTF-8 BOM so
 * spreadsheet programs decode non-ASCII text correctly.
 */
final class CsvWriter
{
    private const BOM = "\u{FEFF}";
    private string $out = self::BOM;

    /** @param list<string|int|float|bool|null> $fields */
    public function row(array $fields): void
    {
        $this->out .= implode(',', array_map(self::field(...), $fields)) . "\r\n";
    }

    public function contents(): string
    {
        return $this->out;
    }

    public static function field(string|int|float|bool|null $v): string
    {
        // numbers and booleans are produced by us and cannot carry a formula; strings may
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v), is_float($v) => (string) $v,
            default => self::neutralise($v),
        };
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /** Makes a cell inert for spreadsheet formula evaluation. */
    public static function neutralise(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        // first non-whitespace character decides (spreadsheets trim leading spaces)
        if (preg_match('/^[\s\x{3000}]*[=+\-@\t\r\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $s) === 1) {
            return "'" . $s;
        }
        return $s;
    }
}
