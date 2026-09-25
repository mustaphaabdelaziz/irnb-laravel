<?php

namespace App\Support;

use App\Support\Export\CsvWriter;
use App\Support\Export\XlsxWriter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every data export and import template goes through here: .xlsx by default,
 * CSV on request (?format=csv). Same code on web and desktop.
 */
final class Export
{
    public const XLSX = 'xlsx';

    public const CSV = 'csv';

    public static function format(Request $request): string
    {
        return $request->query('format') === self::CSV ? self::CSV : self::XLSX;
    }

    /**
     * @param  string  $filename  without extension
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $format, string $filename, array $headers, iterable $rows, ?string $title = null): Response
    {
        $headersHttp = ['Cache-Control' => 'no-cache, no-store, must-revalidate'];

        if ($format === self::CSV) {
            return response()->streamDownload(
                fn () => CsvWriter::stream($headers, $rows, $title),
                $filename.'.csv',
                $headersHttp + ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        }

        $bytes = XlsxWriter::build($headers, $rows, $title, app()->getLocale() === 'ar');

        return response()->streamDownload(
            function () use ($bytes) {
                echo $bytes;
            },
            $filename.'.xlsx',
            $headersHttp + ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
