<?php

namespace App\Support\Import;

/**
 * CSV → rows. UTF-8 (with or without BOM) is read as is; anything that is
 * not valid UTF-8 is taken as Excel's "CSV (ANSI)" on an Arabic Windows,
 * i.e. Windows-1256, which also covers French accented letters. iconv, not
 * mbstring: the desktop PHP's mbstring does not know Windows-1256.
 */
final class CsvReader
{
    private const BOM = "\xEF\xBB\xBF";

    /** @return list<list<string|null>> */
    public static function read(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) iconv('CP1256', 'UTF-8//IGNORE', $content);
        }
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, 3);
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $row === [null] ? [] : $row;
        }
        fclose($handle);

        return $rows;
    }
}
