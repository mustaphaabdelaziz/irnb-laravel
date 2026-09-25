<?php

namespace App\Support;

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
    /**
     * Stream $rows as a downloadable UTF-8 CSV. Any .xls/.xlsx filename is
     * coerced to .csv so the extension matches the real content.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows, ?string $title = null): StreamedResponse
    {
        $base = (string) preg_replace('/\.(csv|xlsx|xls)$/i', '', $filename);

        return Export::download(Export::CSV, $base, $headers, $rows, $title);
    }

    /**
     * Parse an uploaded .xlsx or CSV into 0-indexed rows. The file's first
     * bytes (never its name) decide the format; legacy .xls (OLE) throws so
     * the caller can show "use the provided template" instead of 500ing.
     *
     * @return list<array<int, string|null>>
     */
    public static function readRows(string $path): array
    {
        return Spreadsheet::readRows($path);
    }
}
