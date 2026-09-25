<?php

namespace App\Support\Export;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Minimal .xlsx writer: builds the OOXML parts as plain strings and zips them.
 *
 * The desktop PHP has no ext-xmlwriter / ext-xmlreader (PhpSpreadsheet needs
 * both); it does have ext-zip, which is all this needs. One sheet, inline
 * strings (no shared-string table), a bold frozen header, an optional title
 * row, right-to-left view for Arabic. Style ids: 0 default, 1 header,
 * 2 date, 3 title.
 */
final class XlsxWriter
{
    private const MAX_WIDTH = 60;

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function build(array $headers, iterable $rows, ?string $title, bool $rtl): string
    {
        $xmlRows = [];
        $widths = [];
        $r = 0;

        if ($title !== null && $title !== '') {
            $xmlRows[] = self::row(++$r, [$title], 3, $widths, false);
            $r++; // spacer row
        }
        if ($headers !== []) {
            $xmlRows[] = self::row(++$r, $headers, 1, $widths);
        }
        $frozen = $r;

        foreach ($rows as $row) {
            $xmlRows[] = self::row(++$r, array_values((array) $row), 0, $widths);
        }

        $sheet = self::sheet($xmlRows, $widths, $frozen, $rtl);

        return self::zip([
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rootRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /**
     * @param  list<mixed>  $cells
     * @param  array<int, int>  $widths  column index => longest length (by reference)
     */
    private static function row(int $r, array $cells, int $style, array &$widths, bool $measure = true): string
    {
        $out = '';
        foreach ($cells as $i => $value) {
            $ref = self::column($i + 1).$r;
            $s = $style ? ' s="'.$style.'"' : '';

            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if ($value instanceof DateTimeInterface) {
                $out .= '<c r="'.$ref.'" s="2"><v>'.self::serial($value).'</v></c>';
                $len = 10;
            } elseif (is_int($value) || is_float($value)) {
                $num = is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (string) $value;
                $out .= '<c r="'.$ref.'"'.$s.'><v>'.$num.'</v></c>';
                $len = strlen($num);
            } else {
                $text = (string) $value;
                $out .= '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
                $len = max(array_map('mb_strlen', explode("\n", $text)));
            }

            if ($measure) {
                $widths[$i] = max($widths[$i] ?? 0, $len);
            }
        }

        return '<row r="'.$r.'">'.$out.'</row>';
    }

    /** @param  array<int, int>  $widths */
    private static function sheet(array $xmlRows, array $widths, int $frozen, bool $rtl): string
    {
        $view = '<sheetView workbookViewId="0"'.($rtl ? ' rightToLeft="1"' : '').'>';
        if ($frozen > 0) {
            $view .= '<pane ySplit="'.$frozen.'" topLeftCell="A'.($frozen + 1).'" activePane="bottomLeft" state="frozen"/>';
        }
        $view .= '</sheetView>';

        $cols = '';
        ksort($widths);
        foreach ($widths as $i => $len) {
            $width = min(self::MAX_WIDTH, max(8, $len + 2));
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews>'.$view.'</sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'</worksheet>';
    }

    /** 1 → A, 27 → AA. */
    private static function column(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    /** Excel date serial (1900 system): days since 1899-12-30. */
    private static function serial(DateTimeInterface $date): int
    {
        $utc = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));

        return (int) round(($utc->getTimestamp() / 86400) + 25569);
    }

    private static function escape(string $text): string
    {
        // Control characters other than tab/newline are invalid in XML 1.0.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param  array<string, string>  $parts */
    private static function zip(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the .xlsx archive.');
        }
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
