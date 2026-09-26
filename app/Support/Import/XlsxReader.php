<?php

namespace App\Support\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * .xlsx → rows of the first worksheet, with ZipArchive + SimpleXML only
 * (the desktop PHP has no ext-xmlreader). Handles shared strings, rich text,
 * inline strings, booleans, numbers, gaps between cells/rows, and dates
 * (numeric cells whose style uses a date format → Y-m-d).
 */
final class XlsxReader
{
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Built-in numFmt ids that are dates. */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /**
     * Largest uncompressed part (sheet, shared strings, styles, workbook,
     * rels) we inflate: a tiny zip can hold a gigantic part, and running out
     * of memory is a fatal error no importer can catch.
     */
    public const MAX_PART_BYTES = 50 * 1024 * 1024;

    /** Highest row number accepted; beyond it the file is refused, not padded. */
    public const MAX_ROWS = 100000;

    /** Excel's last column (XFD). */
    private const MAX_COLUMNS = 16384;

    /** The part-size cap in force; tests lower it, production never changes it. */
    public static int $maxPartBytes = self::MAX_PART_BYTES;

    /** @return list<list<string|null>> */
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unreadable .xlsx file.');
        }

        try {
            $sheetXml = self::firstSheet($zip);
            $shared = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
        } finally {
            $zip->close();
        }

        $sheet = self::xml($sheetXml);
        $rows = [];
        $expected = 1;

        foreach ($sheet->sheetData->row as $row) {
            $r = (int) ($row['r'] ?? $expected);
            if ($r > self::MAX_ROWS) {
                throw new RuntimeException('The .xlsx file has too many rows.');
            }
            while ($expected < $r) {
                $rows[] = [];
                $expected++;
            }

            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $index = isset($c['r']) ? self::columnIndex((string) $c['r']) : $next;
                if ($index >= self::MAX_COLUMNS) {
                    throw new RuntimeException('The .xlsx file has too many columns.');
                }
                while (count($cells) < $index) {
                    $cells[] = null;
                }
                $cells[$index] = self::value($c, $shared, $dateStyles);
                $next = $index + 1;
            }
            while ($cells !== [] && end($cells) === null) {
                array_pop($cells);
            }

            $rows[] = array_values($cells);
            $expected = $r + 1;
        }

        return $rows;
    }

    /** @param  list<string>  $shared  @param  array<int, true>  $dateStyles */
    private static function value(SimpleXMLElement $c, array $shared, array $dateStyles): ?string
    {
        $type = (string) ($c['t'] ?? 'n');

        return match ($type) {
            's' => $shared[(int) $c->v] ?? null,
            'inlineStr' => self::text($c->is),
            'b' => ((string) $c->v) === '1' ? '1' : '0',
            'str', 'e' => (string) $c->v,
            default => self::number($c, $dateStyles),
        };
    }

    /** @param  array<int, true>  $dateStyles */
    private static function number(SimpleXMLElement $c, array $dateStyles): ?string
    {
        $raw = (string) $c->v;
        if ($raw === '') {
            return null;
        }
        if (isset($dateStyles[(int) ($c['s'] ?? -1)]) && is_numeric($raw)) {
            return gmdate('Y-m-d', (int) round(((float) $raw - 25569) * 86400));
        }
        if (is_numeric($raw) && str_contains($raw, 'E')) {
            $raw = rtrim(rtrim(sprintf('%.10F', (float) $raw), '0'), '.');
        }

        return $raw;
    }

    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $out = '';
        foreach ($node->r as $run) {
            $out .= (string) $run->t;
        }

        return $out;
    }

    private static function firstSheet(ZipArchive $zip): string
    {
        $target = 'xl/worksheets/sheet1.xml';
        $workbook = self::part($zip, 'xl/workbook.xml');
        $rels = self::part($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $wb = self::xml($workbook);
            $first = $wb->sheets->sheet[0] ?? null;
            // Not `$first ?` — a childless element with only attributes (as
            // <sheet .../> always is) is falsy in SimpleXML's boolean cast,
            // even though it is a perfectly valid, non-null element.
            $rid = $first !== null ? (string) $first->attributes(self::REL_NS)['id'] : '';
            foreach (self::xml($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $t = ltrim((string) $rel['Target'], '/');
                    $target = str_starts_with($t, 'xl/') ? $t : 'xl/'.$t;
                }
            }
        }

        $xml = self::part($zip, $target);
        if ($xml === false) {
            throw new RuntimeException('The .xlsx file has no worksheet.');
        }

        return $xml;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach (self::xml($xml)->si as $si) {
            $out[] = self::text($si);
        }

        return $out;
    }

    /** @return array<int, true> style index => is a date */
    private static function dateStyles(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $styles = self::xml($xml);

        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            // Drop everything shown literally or that is not a date token:
            // "quoted text", [colour / locale / condition], \x escapes, _x
            // padding and *x fill — so "#,##0.00\ \D\A" (a currency) is not
            // taken for a date because of its "D".
            $code = strtolower((string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\.|_.|\*./su', '', (string) $fmt['formatCode']));
            if (preg_match('/[dy]/', $code) || (str_contains($code, 'm') && ! str_contains($code, '0'))) {
                $custom[(int) $fmt['numFmtId']] = true;
            }
        }

        // SimpleXML iteration keys are the tag name; count positions instead.
        $out = [];
        $i = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (in_array($id, self::DATE_FORMAT_IDS, true) || isset($custom[$id])) {
                $out[$i] = true;
            }
            $i++;
        }

        return $out;
    }

    /** A part's contents, or false when absent; a part over the size cap is refused before it is inflated. */
    private static function part(ZipArchive $zip, string $name): string|false
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return false;
        }
        if ($stat['size'] > self::$maxPartBytes) {
            throw new RuntimeException('The .xlsx file is too large to read.');
        }

        return $zip->getFromName($name);
    }

    /** "C12" → 2 */
    private static function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        // LIBXML_NONET: never fetch anything; no entity expansion from the
        // file. Deliberately NOT passing a namespace_or_prefix argument to
        // simplexml_load_string(): every part here already declares its own
        // default xmlns (spreadsheetml main, or package/relationships for
        // .rels files), which SimpleXML resolves on its own for ->child
        // access — but passing that namespace explicitly here breaks plain,
        // unprefixed attribute access ($c['t'], $row['r'], ...), which
        // always comes back empty. Namespaced attributes (r:id) still work
        // via ->attributes(self::REL_NS) regardless.
        $el = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($el === false) {
            throw new RuntimeException('Malformed .xlsx part.');
        }

        return $el;
    }
}
