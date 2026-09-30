<?php

namespace App\Support\Import;

/**
 * CSV → rows. UTF-8 (with or without BOM) is read as is; anything that is
 * not valid UTF-8 is taken as Excel's "CSV (ANSI)" on an Arabic Windows,
 * i.e. Windows-1256, which also covers French accented letters. iconv, not
 * mbstring: the desktop PHP's mbstring does not know Windows-1256.
 *
 * CsvWriter prefixes a cell with a protective apostrophe when it would
 * otherwise read as a formula (starts with `= @ + -`, a tab or a CR); this
 * is the one place CSV cells are read for imports, so it undoes exactly
 * that apostrophe here, letting the app's own exports re-import cleanly. A
 * leading apostrophe followed by anything else is genuine data and is left
 * alone.
 */
final class CsvReader
{
    private const BOM = "\xEF\xBB\xBF";

    /** Characters CsvWriter escapes a cell for; a leading `'` before one of these is its own doing. */
    private const ESCAPED_AFTER_APOSTROPHE = "=@+-\t\r";

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
            $rows[] = $row === [null] ? [] : array_map(self::unprotect(...), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** Strips exactly one leading `'` when CsvWriter would have put it there (see the class doc). */
    private static function unprotect(?string $cell): ?string
    {
        if ($cell === null || ! isset($cell[1]) || $cell[0] !== "'") {
            return $cell;
        }

        return str_contains(self::ESCAPED_AFTER_APOSTROPHE, $cell[1]) ? substr($cell, 1) : $cell;
    }
}
