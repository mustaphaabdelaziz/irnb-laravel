<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams and parses CSV using only core PHP (fputcsv / fgetcsv).
 *
 * The NativePHP desktop build ships a static PHP that OMITS the ext-xmlwriter
 * and ext-xmlreader extensions, which PhpSpreadsheet's .xlsx reader/writer both
 * require — so any .xlsx read/write throws "Class XMLWriter not found" and 500s
 * on the customer's machine (while working fine on the web PHP that has them).
 * CSV needs neither extension and opens natively in Excel, so all import/export
 * flows go through here. A UTF-8 BOM is written so Excel renders Arabic headers.
 */
class Csv
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Stream $rows as a downloadable UTF-8 CSV. Any .xls/.xlsx filename is
     * coerced to .csv so the extension matches the real content.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows, ?string $title = null): StreamedResponse
    {
        $filename = (string) preg_replace('/\.(xlsx|xls)$/i', '.csv', $filename);
        if (! str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }

        return response()->streamDownload(function () use ($headers, $rows, $title) {
            $out = fopen('php://output', 'w');
            fwrite($out, self::BOM);

            if ($title !== null && $title !== '') {
                fputcsv($out, [$title]);
                fputcsv($out, []); // spacer row
            }

            if ($headers !== []) {
                fputcsv($out, $headers);
            }

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::stringify(...), array_values((array) $row)));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Parse an uploaded CSV into 0-indexed rows (BOM stripped, delimiter
     * auto-detected between "," and ";"). Throws if the upload is a binary
     * spreadsheet (.xlsx/.xls) — which cannot be read on the desktop build —
     * so the caller can show "use the provided template" instead of 500ing.
     *
     * @return list<array<int, string|null>>
     */
    public static function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        // .xlsx is a zip ("PK\x03\x04"); legacy .xls is OLE ("\xD0\xCF\x11\xE0").
        // Both are binary and unreadable as CSV — reject rather than yield junk.
        $magic = (string) fread($handle, 4);
        if (str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            fclose($handle);
            throw new RuntimeException('Binary spreadsheet uploaded; expected CSV.');
        }
        rewind($handle);

        // Sniff the delimiter from the first line (Excel in some locales writes ";").
        $firstLine = ltrim((string) fgets($handle), self::BOM);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $rows = [];
        $isFirst = true;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($isFirst) {
                $isFirst = false;
                if (isset($row[0]) && is_string($row[0])) {
                    $row[0] = ltrim($row[0], self::BOM); // strip BOM off the first cell
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
