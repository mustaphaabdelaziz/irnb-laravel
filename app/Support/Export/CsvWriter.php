<?php

namespace App\Support\Export;

/**
 * UTF-8 CSV for Excel: BOM first (Excel's cue to read UTF-8), CRLF line
 * endings, comma delimiter. Core PHP only, so it runs on the desktop build.
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function stream(array $headers, iterable $rows, ?string $title): void
    {
        $out = fopen('php://output', 'w');
        fwrite($out, self::BOM);

        if ($title !== null && $title !== '') {
            self::line($out, [self::stringify($title)]);
            self::line($out, []); // spacer row
        }
        if ($headers !== []) {
            self::line($out, array_map(self::stringify(...), $headers));
        }
        foreach ($rows as $row) {
            self::line($out, array_map(self::stringify(...), array_values((array) $row)));
        }

        fclose($out);
    }

    /** @param  resource  $out */
    private static function line($out, array $cells): void
    {
        if ($cells === []) {
            fwrite($out, "\r\n");

            return;
        }
        fputcsv($out, $cells, ',', '"', '', "\r\n");
    }

    private static function stringify(mixed $value): string
    {
        $out = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };

        // Only a genuine string can carry a spreadsheet formula; a cast int/float/bool/date never needs escaping.
        return is_string($value) ? self::escapeFormula($out) : $out;
    }

    /**
     * Excel/Sheets run a cell starting with =, @, a tab or a CR as a
     * formula: an apostrophe in front forces it back to text. A leading +
     * or - is escaped too, unless it reads as a plain number or phone
     * number (digits, spaces, parentheses and dots only), so "+213 555 12
     * 34" and "-5" are left alone while "-cmd|..." and "+HYPERLINK(...)"
     * are not.
     */
    private static function escapeFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'".$value;
        }
        if (($first === '+' || $first === '-') && ! preg_match('/^[+-][\d\s().]*$/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
