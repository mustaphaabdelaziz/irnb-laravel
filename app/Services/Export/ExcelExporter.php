<?php

namespace App\Services\Export;

use App\Support\Csv;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a title + headers + rows into a downloadable spreadsheet.
 *
 * Emits CSV (not .xlsx) because the NativePHP desktop build's bundled PHP has
 * no ext-xmlwriter, which PhpSpreadsheet's .xlsx writer requires — see
 * {@see \App\Support\Csv}. CSV opens directly in Excel and works identically on
 * web and desktop. The public API is unchanged, so existing callers keep working.
 */
class ExcelExporter
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    public function download(string $title, array $headers, array $rows, string $filename): StreamedResponse
    {
        return Csv::download($filename, $headers, $rows, $title);
    }
}
