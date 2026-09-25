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
            self::line($out, [$title]);
            self::line($out, []); // spacer row
        }
        if ($headers !== []) {
            self::line($out, $headers);
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
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
