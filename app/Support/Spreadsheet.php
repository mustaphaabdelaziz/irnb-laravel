<?php

namespace App\Support;

use App\Support\Import\CsvReader;
use App\Support\Import\XlsxReader;
use RuntimeException;

/** Uploaded import file → rows, chosen by the file's first bytes (never by its name). */
final class Spreadsheet
{
    /** @return list<list<string|null>> */
    public static function readRows(string $path): array
    {
        $magic = (string) @file_get_contents($path, false, null, 0, 4);

        if (str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException('Legacy .xls is not supported; save as .xlsx or CSV.');
        }

        return str_starts_with($magic, "PK\x03\x04") ? XlsxReader::read($path) : CsvReader::read($path);
    }
}
