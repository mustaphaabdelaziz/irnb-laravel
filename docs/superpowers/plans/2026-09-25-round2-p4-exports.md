# Round 2 / P4 — Exports and templates (.xlsx + .csv) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every export and import template downloads as Excel .xlsx (default) or CSV, with headers and values in the user's language, and every import accepts .xlsx, UTF-8 CSV and Windows-1256 CSV — offline, on web and desktop alike.

**Architecture:** A pure-PHP `XlsxWriter` builds the OOXML parts as strings and zips them with `ZipArchive`; a pure-PHP `XlsxReader` reads them back with `ZipArchive` + `SimpleXML`. `App\Support\Export::download()` picks the writer from the request's `format`; `App\Support\Spreadsheet::readRows()` picks the reader from the file's magic bytes. Importers find their columns through `ImportColumns` (header text in ar/fr/en, legacy headers, then position). The browser-side SheetJS conversion and the unused PhpSpreadsheet dependency are removed.

**Tech Stack:** Laravel 13, PHP 8.3 (desktop: bundled static `php.exe` 8.3.31), Inertia + Vue 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-22-post-testing-round-2-design.md`, section "P4 — Exports and templates", plus the owner decisions below.

## Owner decisions (2026-09-25)

1. **.xlsx imports are read on the server** (pure PHP) for all four importers. The client-side SheetJS conversion and the `xlsx` npm package are removed. *(Replaces the spec's "transactions import gets the SheetJS conversion".)*
2. **Garbled CSV:** investigation found the server output correct — the response starts with `EF BB BF`, the Arabic is valid UTF-8, no PHP file emits bytes before it, and the BOM predates the report. The cause is how the file was opened in Excel. Fix: .xlsx becomes the default, CSV keeps the BOM and switches to CRLF. Ask the owner in QA how they opened the file.
3. **Transactions import reads the Cash Register column:** match an existing register by name in any language. When nothing matches, leave `finance_account_id` empty; `TransactionObserver::saving` then applies the default register (`DefaultRegisterResolver::for(null, null)`), and the import reports a row warning.
4. **Remove `phpoffice/phpspreadsheet`** (unused), then re-apply the NativePHP afterPack patch.

## Global Constraints

- **Offline only.** No CDN, no remote API, no online-only library, at runtime or in the desktop bundle. After `npm run build`, `grep -rE "https?://" public/build/assets` must show no external host.
- **No XMLWriter, no XMLReader** anywhere (the desktop PHP lacks both). Allowed: `ZipArchive`, `SimpleXML`, `iconv`, `mbstring`. `mb_convert_encoding` does NOT know Windows-1256 on the desktop PHP — use `iconv('CP1256', 'UTF-8', …)`.
- One code path for web and desktop.
- i18n: flat dotted keys, call `t()` / `UiLang::get()` directly, never `te()`. Add keys only with `node scripts/i18n-add.mjs <file.json>`; every key has ar, fr and en; `npm run i18n:check` passes.
- CSV imports stay backward compatible: files made from today's templates (Arabic/English headers, today's column order) still import.
- Tests: PHPUnit class style, `#[Test]`, `RefreshDatabase`, run with `composer test` (PowerShell) — it clears the config cache first.
- BOM files (`resources/js/app.js`, `Players/Index.vue`, `Players/Show.vue` and the rest of the list in `.superpowers/sdd/r2p2-constraints.md`): targeted edits only, keep the BOM.
- Stage explicit files only, never `git add -A`. Commit trailer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- Routes: a new static route under `/players/...` must be declared before `Route::resource('players')`.
- Pint: leave the 7 pre-existing flagged files alone.

## File structure

| File | Responsibility |
|---|---|
| `app/Support/Export/XlsxWriter.php` (new) | Rows → .xlsx bytes (strings + ZipArchive). |
| `app/Support/Export/CsvWriter.php` (new) | Rows → CSV stream (BOM, CRLF). Body of today's `Csv::download`. |
| `app/Support/Export.php` (new) | `download()` dispatcher + `format()` from the request. |
| `app/Support/Import/XlsxReader.php` (new) | .xlsx → rows (ZipArchive + SimpleXML). |
| `app/Support/Import/CsvReader.php` (new) | CSV → rows with CP1256 fallback. Body of today's `Csv::readRows`. |
| `app/Support/Spreadsheet.php` (new) | `readRows()` dispatcher by magic bytes. |
| `app/Support/Import/ImportColumns.php` (new) | Header row detection + header → column map + localized value matching. |
| `app/Support/Csv.php` | Kept as thin deprecated forwarders until Task 7, then deleted. |
| `app/Services/Export/ExcelExporter.php` | Deleted in Task 7. |
| `resources/js/Components/ExportMenu.vue` (new) | .xlsx / .csv dropdown. |

---

### Task 1: XlsxWriter, CsvWriter, Export dispatcher

**Files:**
- Create: `app/Support/Export/XlsxWriter.php`, `app/Support/Export/CsvWriter.php`, `app/Support/Export.php`
- Modify: `app/Support/Csv.php` (`download()` forwards to `CsvWriter`)
- Test: `tests/Unit/XlsxWriterTest.php`, `tests/Feature/ExportDownloadTest.php`

**Interfaces:**
- Produces:
  - `XlsxWriter::build(array $headers, iterable $rows, ?string $title, bool $rtl): string` — the .xlsx bytes.
  - `CsvWriter::stream(array $headers, iterable $rows, ?string $title): void` — writes to `php://output`.
  - `Export::download(string $format, string $filename, array $headers, iterable $rows, ?string $title = null): Symfony\Component\HttpFoundation\Response` — `$format` is `'xlsx'` or `'csv'`; `$filename` has no extension (the writer adds it).
  - `Export::format(Illuminate\Http\Request $request): string` — `'csv'` when `?format=csv`, otherwise `'xlsx'`.
  - Cell typing rule: `int`/`float` → number cell; `DateTimeInterface` → date cell (numFmt `yyyy-mm-dd`); `null` → empty; everything else → inline string. **Strings are never turned into numbers**, so `"0003"` keeps its leading zeros.

- [ ] **Step 1: Write the failing unit test** — `tests/Unit/XlsxWriterTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\Export\XlsxWriter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class XlsxWriterTest extends TestCase
{
    /** @return array<string,string> part name => xml */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $parts;
    }

    #[Test]
    public function it_builds_a_valid_package_with_arabic_and_french_intact(): void
    {
        $parts = $this->unzip(XlsxWriter::build(['الاسم', 'Catégorie'], [['محمد بن علي', 'Séniors']], null, false));

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertArrayHasKey($part, $parts);
            $this->assertNotFalse(simplexml_load_string($parts[$part]), "$part is not well-formed XML");
        }
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('محمد بن علي', $sheet);
        $this->assertStringContainsString('Séniors', $sheet);
        $this->assertStringContainsString('Catégorie', $sheet);
    }

    #[Test]
    public function numbers_stay_numbers_dates_become_dates_and_strings_keep_leading_zeros(): void
    {
        $sheet = $this->unzip(XlsxWriter::build(['a', 'b', 'c', 'd'], [[2000.5, 7, '0003', Carbon::parse('2026-01-15')]], null, false))['xl/worksheets/sheet1.xml'];

        $this->assertMatchesRegularExpression('#<c r="A2"[^>]*><v>2000.5</v></c>#', $sheet);
        $this->assertMatchesRegularExpression('#<c r="B2"[^>]*><v>7</v></c>#', $sheet);
        $this->assertMatchesRegularExpression('#<c r="C2" [^>]*t="inlineStr"[^>]*><is><t[^>]*>0003</t></is></c>#', $sheet);
        // 2026-01-15 is Excel serial 46037.
        $this->assertMatchesRegularExpression('#<c r="D2" s="2"><v>46037</v></c>#', $sheet);
    }

    #[Test]
    public function the_header_is_bold_and_frozen_and_a_title_row_pushes_it_down(): void
    {
        $sheet = $this->unzip(XlsxWriter::build(['h1', 'h2'], [['x', 'y']], 'Players', false))['xl/worksheets/sheet1.xml'];

        $this->assertMatchesRegularExpression('#<c r="A1" [^>]*s="3"[^>]*><is><t[^>]*>Players</t>#', $sheet);   // title
        $this->assertMatchesRegularExpression('#<c r="A3" [^>]*s="1"[^>]*><is><t[^>]*>h1</t>#', $sheet);        // bold header on row 3
        $this->assertStringContainsString('<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>', $sheet);
        $this->assertStringContainsString('<c r="A4"', $sheet);
    }

    #[Test]
    public function rtl_sets_the_sheet_view_right_to_left(): void
    {
        $this->assertStringContainsString('rightToLeft="1"', $this->unzip(XlsxWriter::build(['a'], [], null, true))['xl/worksheets/sheet1.xml']);
        $this->assertStringNotContainsString('rightToLeft', $this->unzip(XlsxWriter::build(['a'], [], null, false))['xl/worksheets/sheet1.xml']);
    }

    #[Test]
    public function xml_special_characters_and_control_characters_are_escaped(): void
    {
        $sheet = $this->unzip(XlsxWriter::build(['a'], [["A & B <c> \"d\"\x01"]], null, false))['xl/worksheets/sheet1.xml'];

        $this->assertNotFalse(simplexml_load_string($sheet));
        $this->assertStringContainsString('A &amp; B &lt;c&gt;', $sheet);
    }

    #[Test]
    public function column_widths_follow_the_longest_cell(): void
    {
        $sheet = $this->unzip(XlsxWriter::build(['id', 'name'], [['1', str_repeat('x', 40)]], null, false))['xl/worksheets/sheet1.xml'];

        $this->assertMatchesRegularExpression('#<col min="2" max="2" width="4[0-9](\.[0-9]+)?" customWidth="1"/>#', $sheet);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`Class "App\Support\Export\XlsxWriter" not found`)

Run (PowerShell): `php artisan test --filter=XlsxWriterTest`

- [ ] **Step 3: Implement `app/Support/Export/XlsxWriter.php`**

```php
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
```

(The `schemas.openxmlformats.org` strings are XML namespace identifiers, not network requests.)

- [ ] **Step 4: Run the unit test — expect PASS.** Fix any regex that disagrees with the output by fixing the writer, not the test's meaning.

- [ ] **Step 5: Write the failing feature test** — `tests/Feature/ExportDownloadTest.php`

```php
<?php

namespace Tests\Feature;

use App\Support\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function body($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    #[Test]
    public function xlsx_is_the_default_format_and_csv_is_opt_in(): void
    {
        $this->assertSame('xlsx', Export::format(Request::create('/x')));
        $this->assertSame('xlsx', Export::format(Request::create('/x?format=junk')));
        $this->assertSame('csv', Export::format(Request::create('/x?format=csv')));
    }

    #[Test]
    public function the_xlsx_download_has_the_right_type_name_and_zip_signature(): void
    {
        $response = Export::download('xlsx', 'players-2026-09-25', ['a'], [['b']]);

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
        $this->assertStringContainsString('players-2026-09-25.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $this->body($response));
    }

    #[Test]
    public function the_csv_download_starts_with_a_bom_uses_crlf_and_keeps_arabic(): void
    {
        $body = $this->body(Export::download('csv', 'x', ['الاسم', 'Nom'], [['محمد', 'Éric']], 'العنوان'));

        $this->assertSame('efbbbf', bin2hex(substr($body, 0, 3)));
        $this->assertStringContainsString("العنوان\r\n\r\n", $body);
        $this->assertStringContainsString("الاسم,Nom\r\n", $body);
        $this->assertStringContainsString("محمد,Éric\r\n", $body);
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", '', $body));
    }

    #[Test]
    public function the_sheet_is_right_to_left_when_the_locale_is_arabic(): void
    {
        app()->setLocale('ar');
        $bytes = $this->body(Export::download('xlsx', 'x', ['a'], []));
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $zip->open($path);

        $this->assertStringContainsString('rightToLeft="1"', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
    }
}
```

- [ ] **Step 6: Run it — expect FAIL** (`Class "App\Support\Export" not found`).

- [ ] **Step 7: Implement `CsvWriter` and `Export`**

`app/Support/Export/CsvWriter.php`:

```php
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
```

`app/Support/Export.php`:

```php
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
```

In `app/Support/Csv.php`, replace the body of `download()` with a forward that keeps its current signature and `.csv` coercion, so existing callers still work until Task 4:

```php
    public static function download(string $filename, array $headers, iterable $rows, ?string $title = null): StreamedResponse
    {
        $base = (string) preg_replace('/\.(csv|xlsx|xls)$/i', '', $filename);

        return Export::download(Export::CSV, $base, $headers, $rows, $title);
    }
```

(Remove the now-unused `BOM` constant usage in `download`; `readRows` still uses `self::BOM` — keep the constant.)

- [ ] **Step 8: Run `php artisan test --filter="XlsxWriterTest|ExportDownloadTest"` — expect PASS. Then `composer test` — expect all green** (existing tests assert `text/csv; charset=UTF-8`, which is unchanged).

- [ ] **Step 9: Desktop smoke test.** Save to the scratchpad and run with the bundled PHP:

```php
<?php // smoke-xlsx.php — run from the repo root
require 'vendor/autoload.php';
$bytes = App\Support\Export\XlsxWriter::build(['الاسم', 'Montant'], [['محمد', 2000.5], ['Éric', 7]], 'Test', true);
file_put_contents(sys_get_temp_dir().'/smoke.xlsx', $bytes);
echo strlen($bytes) > 500 && str_starts_with($bytes, "PK\x03\x04") ? "OK\n" : "FAIL\n";
```

Run: `vendor/nativephp/desktop/resources/build/php/php.exe <scratchpad>/smoke-xlsx.php` → `OK`. Open `smoke.xlsx` in Excel (or LibreOffice) once, by hand: right-to-left, bold frozen header, Arabic intact.

- [ ] **Step 10: Commit**

```bash
git add app/Support/Export.php app/Support/Export/XlsxWriter.php app/Support/Export/CsvWriter.php app/Support/Csv.php tests/Unit/XlsxWriterTest.php tests/Feature/ExportDownloadTest.php
git commit -m "feat(export): pure-PHP xlsx writer and CSV with CRLF behind one Export entry point"
```

---

### Task 2: Server-side readers (xlsx + CP1256 CSV)

**Files:**
- Create: `app/Support/Import/XlsxReader.php`, `app/Support/Import/CsvReader.php`, `app/Support/Spreadsheet.php`
- Modify: `app/Support/Csv.php` (`readRows()` forwards to `Spreadsheet::readRows()`)
- Test: `tests/Unit/SpreadsheetReaderTest.php`

**Interfaces:**
- Consumes: `XlsxWriter::build()` (Task 1) to make fixtures.
- Produces: `Spreadsheet::readRows(string $path): list<list<string|null>>` — 0-indexed rows of trimmed-free strings (callers trim), dates as `Y-m-d`, numbers as their plain string (`"2000"`, `"2000.5"`). Throws `RuntimeException` for legacy `.xls` (OLE) or an unreadable zip.

- [ ] **Step 1: Write the failing test** — `tests/Unit/SpreadsheetReaderTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\Export\XlsxWriter;
use App\Support\Spreadsheet;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    private function file(string $bytes, string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rd').'.'.$ext;
        file_put_contents($path, $bytes);

        return $path;
    }

    #[Test]
    public function it_reads_back_an_xlsx_made_by_the_writer(): void
    {
        $path = $this->file(XlsxWriter::build(['الاسم', 'Montant', 'Date'], [['محمد بن علي', 2000.5, Carbon::parse('2026-01-15')], ['Éric', 7, null]], 'Titre', false), 'xlsx');

        $rows = Spreadsheet::readRows($path);

        $this->assertSame(['Titre'], $rows[0]);
        $this->assertSame([], array_filter($rows[1], fn ($c) => $c !== null && $c !== ''));
        $this->assertSame(['الاسم', 'Montant', 'Date'], $rows[2]);
        $this->assertSame(['محمد بن علي', '2000.5', '2026-01-15'], $rows[3]);
        $this->assertSame(['Éric', '7'], array_slice($rows[4], 0, 2));
    }

    #[Test]
    public function it_reads_shared_strings_and_gaps_like_excel_writes_them(): void
    {
        $zip = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'rd').'.xlsx';
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="A" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>قميص</t></si><si><r><t>Bal</t></r><r><t>lon</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row><row r="3"><c r="B3"><v>42</v></c></row></sheetData></worksheet>');
        $zip->close();

        $rows = Spreadsheet::readRows($path);

        $this->assertSame(['قميص', null, 'Ballon'], $rows[0]);
        $this->assertSame([], $rows[1]);
        $this->assertSame([null, '42'], $rows[2]);
    }

    #[Test]
    public function a_windows_1256_csv_is_converted_to_utf8(): void
    {
        // "الاسم,Prénom\r\nمحمد,Éric\r\n" in Windows-1256.
        $cp1256 = iconv('UTF-8', 'CP1256', "الاسم,Prénom\r\nمحمد,Éric\r\n");
        $rows = Spreadsheet::readRows($this->file($cp1256, 'csv'));

        $this->assertSame(['الاسم', 'Prénom'], $rows[0]);
        $this->assertSame(['محمد', 'Éric'], $rows[1]);
    }

    #[Test]
    public function a_utf8_csv_with_bom_and_semicolons_still_reads(): void
    {
        $rows = Spreadsheet::readRows($this->file("\xEF\xBB\xBFa;b\r\nمحمد;2\r\n", 'csv'));

        $this->assertSame(['a', 'b'], $rows[0]);
        $this->assertSame(['محمد', '2'], $rows[1]);
    }

    #[Test]
    public function a_legacy_xls_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Spreadsheet::readRows($this->file("\xD0\xCF\x11\xE0junk", 'xls'));
    }

    #[Test]
    public function a_corrupt_zip_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Spreadsheet::readRows($this->file("PK\x03\x04not really a zip", 'xlsx'));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`Class "App\Support\Spreadsheet" not found`).

- [ ] **Step 3: Implement**

`app/Support/Import/CsvReader.php` — move the body of today's `Csv::readRows()` here (from `rewind($handle)` after the magic check to the end), and add the encoding step: read the whole file, and if `! mb_check_encoding($content, 'UTF-8')`, convert it with `iconv('CP1256', 'UTF-8//IGNORE', $content)` before parsing. Parse from a `php://temp` stream holding the (converted) content:

```php
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
```

`app/Support/Import/XlsxReader.php`:

```php
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
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Built-in numFmt ids that are dates. */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

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
            while ($expected < $r) {
                $rows[] = [];
                $expected++;
            }

            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $index = isset($c['r']) ? self::columnIndex((string) $c['r']) : $next;
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
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $wb = self::xml($workbook);
            $first = $wb->sheets->sheet[0] ?? null;
            $rid = $first ? (string) $first->attributes(self::REL_NS)['id'] : '';
            foreach (self::xml($rels, 'http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $t = ltrim((string) $rel['Target'], '/');
                    $target = str_starts_with($t, 'xl/') ? $t : 'xl/'.$t;
                }
            }
        }

        $xml = $zip->getFromName($target);
        if ($xml === false) {
            throw new RuntimeException('The .xlsx file has no worksheet.');
        }

        return $xml;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
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
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $styles = self::xml($xml);

        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $fmt['formatCode']));
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

    private static function xml(string $xml, string $ns = self::NS): SimpleXMLElement
    {
        // LIBXML_NONET: never fetch anything; no entity expansion from the file.
        $el = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT, $ns);
        if ($el === false) {
            throw new RuntimeException('Malformed .xlsx part.');
        }

        return $el;
    }
}
```

`app/Support/Spreadsheet.php`:

```php
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
```

In `app/Support/Csv.php`, replace the body of `readRows()` with `return Spreadsheet::readRows($path);` and update its docblock ("Parse an uploaded .xlsx or CSV…").

- [ ] **Step 4: Run `php artisan test --filter=SpreadsheetReaderTest` — expect PASS; then `composer test` — all green.**

- [ ] **Step 5: Desktop smoke.** Append to the scratchpad smoke script: `var_dump(App\Support\Spreadsheet::readRows(sys_get_temp_dir().'/smoke.xlsx'));` and run it with the bundled `php.exe` — the Arabic row prints intact.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Spreadsheet.php app/Support/Import/XlsxReader.php app/Support/Import/CsvReader.php app/Support/Csv.php tests/Unit/SpreadsheetReaderTest.php
git commit -m "feat(import): read .xlsx on the server and Windows-1256 CSV, pure PHP"
```

---

### Task 3: ImportColumns — header detection, header aliases, localized values

**Files:**
- Create: `app/Support/Import/ImportColumns.php`
- Test: `tests/Unit/ImportColumnsTest.php`

**Interfaces:**
- Consumes: `UiLang::get(string $key, ?string $default, ?string $locale)`, `NameNormalizer::key(?string): string`.
- Produces:
  - `new ImportColumns(array $columns)` where `$columns` is `list<array{key:string, label:string, legacy?:list<string>}>` — `label` is an i18n key (e.g. `col.firstname`), `legacy` lists header texts used by older templates/exports.
  - `->locate(list<list<string|null>> $rows): array{0:int, 1:array<string,int>}` — returns `[$headerRowIndex, $fieldKey => $columnIndex]`. Data rows are the ones after `$headerRowIndex`.
  - `->headers(?string $locale = null): list<string>` — header texts in the given/current locale (for templates).
  - `static ImportColumns::value(?string $raw, array $codes): ?string` — `$codes` is `array<string code, string labelKey>`; returns the code whose code, or ar/fr/en label, matches `$raw` (normalized), else `null`.

**Rules for `locate()`:**
1. Look at the first 5 rows. The header row is the first row where at least 2 cells (or 1 when the definition has a single column) match a known header; the export's title row and blank spacer row therefore get skipped.
2. Matching: normalize both sides with `NameNormalizer::key()` after stripping a trailing parenthetical hint (`"Date (YYYY-MM-DD)"` → `"date"`). Known headers of a column = its label in ar, fr, en + its `legacy` texts. Try exact (unstripped) normalized match first, then stripped.
3. When one header text matches several columns, it goes to the first column in definition order that is not yet assigned (e.g. legacy players file: first `الولاية` → `state`, later `الولاية (الرمز أو الاسم)` → `wilaya`).
4. Columns not found by header fall back to their position in the definition, but only if that position is not already taken by another matched column.
5. No header row found → header row is 0 and every column uses its position (today's behaviour).

- [ ] **Step 1: Write the failing test** — `tests/Unit/ImportColumnsTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\Import\ImportColumns;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportColumnsTest extends TestCase
{
    private function columns(): ImportColumns
    {
        return new ImportColumns([
            ['key' => 'transaction_date', 'label' => 'col.date', 'legacy' => ['Date (YYYY-MM-DD)']],
            ['key' => 'title', 'label' => 'col.title', 'legacy' => ['Title']],
            ['key' => 'amount', 'label' => 'col.amount', 'legacy' => ['Amount']],
        ]);
    }

    #[Test]
    public function it_finds_headers_in_any_of_the_three_languages_in_any_order(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $headers = $this->columns()->headers($locale);
            [$row, $map] = $this->columns()->locate([[$headers[2], $headers[0], $headers[1]]]);

            $this->assertSame(0, $row, $locale);
            $this->assertSame(['transaction_date' => 1, 'title' => 2, 'amount' => 0], $map, $locale);
        }
    }

    #[Test]
    public function it_skips_the_export_title_and_spacer_rows(): void
    {
        [$row, $map] = $this->columns()->locate([['Transactions'], [], ['Date', 'Title', 'Amount'], ['2026-01-01', 'x', '5']]);

        $this->assertSame(2, $row);
        $this->assertSame(['transaction_date' => 0, 'title' => 1, 'amount' => 2], $map);
    }

    #[Test]
    public function legacy_headers_and_hints_in_parentheses_match(): void
    {
        [, $map] = $this->columns()->locate([['Date (YYYY-MM-DD)', 'Amount (DZD)', 'Title']]);

        $this->assertSame(['transaction_date' => 0, 'title' => 2, 'amount' => 1], $map);
    }

    #[Test]
    public function without_a_recognised_header_it_falls_back_to_position(): void
    {
        [$row, $map] = $this->columns()->locate([['h', 'h', 'h'], ['2026-01-01', 'x', '5']]);

        $this->assertSame(0, $row);
        $this->assertSame(['transaction_date' => 0, 'title' => 1, 'amount' => 2], $map);
    }

    #[Test]
    public function a_repeated_header_goes_to_the_next_free_column(): void
    {
        $cols = new ImportColumns([
            ['key' => 'state', 'label' => 'col.state', 'legacy' => ['الولاية']],
            ['key' => 'wilaya', 'label' => 'col.wilaya', 'legacy' => ['الولاية (الرمز أو الاسم)']],
        ]);

        [, $map] = $cols->locate([['الولاية', 'الولاية (الرمز أو الاسم)']]);

        $this->assertSame(['state' => 0, 'wilaya' => 1], $map);
    }

    #[Test]
    public function values_match_codes_or_labels_in_any_language(): void
    {
        $codes = ['income' => 'income', 'expense' => 'expense'];

        $this->assertSame('expense', ImportColumns::value('expense', $codes));
        $this->assertSame('expense', ImportColumns::value(' EXPENSE ', $codes));
        foreach (['ar', 'fr', 'en'] as $locale) {
            $this->assertSame('income', ImportColumns::value(\App\Support\UiLang::get('income', null, $locale), $codes), $locale);
        }
        $this->assertNull(ImportColumns::value('nonsense', $codes));
        $this->assertNull(ImportColumns::value(null, $codes));
    }
}
```

(Keys `col.date`, `col.title`, `col.amount`, `col.state`, `col.wilaya` come from Step 3's key file; `income`/`expense` must already exist in the catalogs — confirm with `grep -n '"income"\|"expense"' resources/js/i18n/en.json`; if a key has another name, use it in the test.)

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Add the column label keys.** Write `<scratchpad>/p4-col-keys.json` with **all** `col.*` keys below (Tasks 4–6 use them too) and run `node scripts/i18n-add.mjs <scratchpad>/p4-col-keys.json`, then `npm run i18n:check`.

| key | ar | fr | en |
|---|---|---|---|
| col.membership_id | رقم الانخراط | N° d'adhésion | Membership ID |
| col.file_number | رقم الملف | N° de dossier | File number |
| col.wilaya | الولاية | Wilaya | Wilaya |
| col.state | الولاية (نص قديم) | Wilaya (ancien texte) | Wilaya (old text) |
| col.main_position | المركز الرئيسي | Poste principal | Main position |
| col.other_positions | مراكز أخرى | Autres postes | Other positions |
| col.full_name | الاسم الكامل | Nom complet | Full name |
| col.firstname | الاسم | Prénom | First name |
| col.lastname | اللقب | Nom | Last name |
| col.father | اسم الأب | Prénom du père | Father's name |
| col.grandfather | اسم الجد | Prénom du grand-père | Grandfather's name |
| col.nickname | الكنية | Surnom | Nickname |
| col.birthdate | تاريخ الميلاد | Date de naissance | Birth date |
| col.gender | الجنس | Sexe | Gender |
| col.phone | الهاتف | Téléphone | Phone |
| col.phones | الهواتف | Téléphones | Phones |
| col.email | البريد الإلكتروني | E-mail | Email |
| col.city | المدينة | Ville | City |
| col.category | الفئة | Catégorie | Category |
| col.position | المركز | Poste | Position |
| col.job | المهنة | Profession | Job |
| col.player_type | طالب أو عامل | Étudiant ou travailleur | Student or worker |
| col.skill_level | المستوى | Niveau | Skill level |
| col.blood_group | فصيلة الدم | Groupe sanguin | Blood group |
| col.medical_conditions | الحالات الصحية | Antécédents médicaux | Medical conditions |
| col.join_year | سنة الانضمام | Année d'adhésion | Join year |
| col.status | الحالة | Statut | Status |
| col.debt | الدين | Dette | Debt |
| col.branches | الفروع | Sections | Branches |
| col.player_name | اسم اللاعب | Nom du joueur | Player name |
| col.amount_owed | المبلغ المستحق | Montant dû | Amount owed |
| col.amount_paid | المبلغ المدفوع | Montant payé | Amount paid |
| col.total | المجموع | Total | Total |
| col.date | التاريخ | Date | Date |
| col.title | العنوان | Intitulé | Title |
| col.type | النوع | Type | Type |
| col.amount | المبلغ | Montant | Amount |
| col.payment_method | طريقة الدفع | Mode de paiement | Payment method |
| col.cash_register | الصندوق | Caisse | Cash register |
| col.description | الوصف | Description | Description |
| col.recorded_by | سجّله | Saisi par | Recorded by |
| col.name | الاسم | Nom | Name |
| col.brand | العلامة التجارية | Marque | Brand |
| col.purchase_price | سعر الشراء | Prix d'achat | Purchase price |
| col.total_units | عدد الوحدات | Nombre d'unités | Total units |
| col.serial | الرقم التسلسلي | N° de série | Serial number |
| col.designation | التسمية | Désignation | Designation |
| col.condition | الوضعية | État | Condition |
| col.location | الموقع | Emplacement | Location |
| col.purchase_date | تاريخ الشراء | Date d'achat | Purchase date |
| col.notes | ملاحظات | Notes | Notes |
| col.item | العتاد | Article | Item |
| col.catalog | الصنف | Catalogue | Catalog |
| col.expected_status | الحالة المتوقعة | Statut attendu | Expected status |
| col.expected_condition | الوضعية المتوقعة | État attendu | Expected condition |
| col.expected_location | الموقع المتوقع | Emplacement attendu | Expected location |
| col.result | النتيجة | Résultat | Result |
| col.actual_condition | الوضعية الفعلية | État constaté | Actual condition |
| col.actual_location | الموقع الفعلي | Emplacement constaté | Actual location |
| col.note | ملاحظة | Note | Note |
| col.role | المنصب | Fonction | Role |
| col.term_start | بداية العهدة | Début du mandat | Term start |
| col.term_end | نهاية العهدة | Fin du mandat | Term end |
| col.assignee | المكلَّف | Responsable | Assignee |
| col.meeting | الاجتماع | Réunion | Meeting |
| col.priority | الأولوية | Priorité | Priority |
| col.progress | التقدم | Avancement | Progress |
| col.due_date | تاريخ الاستحقاق | Échéance | Due date |
| col.member | العضو | Membre | Member |
| col.drawer | الدرج | Tiroir | Drawer |
| col.found | موجود | Trouvé | Found |
| col.missing | مفقود | Manquant | Missing |
| col.hint.date | YYYY-MM-DD | AAAA-MM-JJ | YYYY-MM-DD |
| col.hint.code_or_name | الرمز أو الاسم | code ou nom | code or name |
| col.hint.abbr_or_name | الاختصار أو الاسم | abréviation ou nom | abbreviation or name |
| col.hint.comma_separated | مفصولة بفاصلة | séparés par une virgule | comma-separated |
| col.hint.one_to_ten | 1-10 | 1-10 | 1-10 |

- [ ] **Step 4: Implement `app/Support/Import/ImportColumns.php`**

```php
<?php

namespace App\Support\Import;

use App\Support\NameNormalizer;
use App\Support\UiLang;

/**
 * Finds an import's columns by header text in ar / fr / en (or an older
 * template's header), falling back to position — so a template downloaded in
 * French imports fine for an Arabic user, and old files keep working.
 */
final class ImportColumns
{
    private const LOCALES = ['ar', 'fr', 'en'];

    private const SCAN_ROWS = 5;

    /** @param  list<array{key:string, label:string, legacy?:list<string>}>  $columns */
    public function __construct(private readonly array $columns) {}

    /** @return list<string> */
    public function headers(?string $locale = null): array
    {
        return array_map(fn (array $c) => UiLang::get($c['label'], null, $locale), $this->columns);
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @return array{0:int, 1:array<string,int>}
     */
    public function locate(array $rows): array
    {
        $needed = count($this->columns) > 1 ? 2 : 1;

        foreach (array_slice($rows, 0, self::SCAN_ROWS, true) as $i => $row) {
            $matched = $this->match($row);
            if (count($matched) >= $needed) {
                return [$i, $this->withPositions($matched)];
            }
        }

        return [0, $this->withPositions([])];
    }

    /**
     * @param  array<string, string>  $codes  code => i18n label key
     */
    public static function value(?string $raw, array $codes): ?string
    {
        $needle = NameNormalizer::key($raw);
        if ($needle === '') {
            return null;
        }
        foreach ($codes as $code => $labelKey) {
            if (NameNormalizer::key((string) $code) === $needle) {
                return (string) $code;
            }
            foreach (self::LOCALES as $locale) {
                if (NameNormalizer::key(UiLang::get($labelKey, null, $locale)) === $needle) {
                    return (string) $code;
                }
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function match(array $row): array
    {
        $known = [];
        foreach ($this->columns as $c) {
            $texts = $c['legacy'] ?? [];
            foreach (self::LOCALES as $locale) {
                $texts[] = UiLang::get($c['label'], null, $locale);
            }
            $known[$c['key']] = [
                'exact' => array_unique(array_map(NameNormalizer::key(...), $texts)),
                'stripped' => array_unique(array_map(fn ($t) => NameNormalizer::key(self::strip($t)), $texts)),
            ];
        }

        $map = [];
        foreach ($row as $index => $cell) {
            if (! is_string($cell) || trim($cell) === '') {
                continue;
            }
            foreach (['exact' => NameNormalizer::key($cell), 'stripped' => NameNormalizer::key(self::strip($cell))] as $kind => $needle) {
                foreach ($this->columns as $c) {
                    if (! isset($map[$c['key']]) && in_array($needle, $known[$c['key']][$kind], true)) {
                        $map[$c['key']] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    /** @param  array<string, int>  $matched */
    private function withPositions(array $matched): array
    {
        $taken = array_flip($matched);
        $map = [];
        foreach ($this->columns as $position => $c) {
            if (isset($matched[$c['key']])) {
                $map[$c['key']] = $matched[$c['key']];
            } elseif (! isset($taken[$position])) {
                $map[$c['key']] = $position;
            }
        }

        return $map;
    }

    private static function strip(string $text): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $text));
    }
}
```

Check `NameNormalizer::key()`'s behaviour first (`app/Support/NameNormalizer.php:27`): it must lowercase, fold accents and Arabic letter forms and collapse spaces. If it strips digits or punctuation that the hints need, the tests will show it — adapt `strip()`, not `NameNormalizer`.

- [ ] **Step 5: Run `php artisan test --filter=ImportColumnsTest` — expect PASS; `npm run i18n:check` — clean.**

- [ ] **Step 6: Commit**

```bash
git add app/Support/Import/ImportColumns.php tests/Unit/ImportColumnsTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(import): find import columns by header in ar/fr/en, legacy headers or position"
```

---

### Task 4: Localized exports on every screen (format param)

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php:540-564` (`export`), `app/Http/Controllers/SubscriptionController.php:333-381` (`export`), `app/Http/Controllers/TransactionController.php:66-87` (`export`), `app/Http/Controllers/EquipmentCatalogController.php:205-224` (`export`), `app/Http/Controllers/EquipmentItemController.php:458-474` (`export`), `app/Http/Controllers/InventoryController.php:213-229` (`export`), `app/Http/Controllers/BoardController.php:264-282` (`exportMembers`, `exportTasks`), `app/Http/Controllers/PlayerPrintController.php:69-99` (`boardTable`)
- Modify tests that assert the CSV content: `tests/Feature/PlayerListActionsTest.php`, `PlayerFileNumberTest.php`, `PlayerPositionsTest.php`, `PlayerWilayaTest.php`, `BranchFeatureTest.php`, `EquipmentCatalogImportExportTest.php`, `EquipmentImportExportTest.php` — they request `?format=csv` so their string assertions keep working.
- Test: `tests/Feature/LocalizedExportsTest.php`

**Interfaces:**
- Consumes: `Export::download()`, `Export::format()` (Task 1), `col.*` keys (Task 3), `UiLang::get()`.
- Produces: every export route accepts `?format=xlsx|csv` (default xlsx). `players.board-table` additionally accepts `format=pdf` and **keeps PDF as its default** (it is a printable board sheet; xlsx/csv are extra).

**Rules (apply to every export):**
- Call `Export::download(Export::format($request), '<base filename without extension>', $headers, $rows, $title)`; inject `Request $request` where the method lacks it. Filenames keep today's base names (`players-Y-m-d`, `transactions-Y-m-d`, `inventory-{ref}`, `board-members`, `board-tasks`, …).
- Headers: `UiLang::get('col.xxx')` for each column, in today's order. The `#` column stays the literal `#`.
- Title: translated with an existing screen key where one exists (the page title key the Vue page uses — find it with `grep -n "t('" resources/js/Pages/<Page>.vue | head`), else keep today's text.
- Values:
  - Amounts, debts, counts, progress → PHP `float`/`int` (progress as the integer percent, not `"N%"`); dates → `Carbon` objects (not `format('Y-m-d')` strings) so the writer makes date cells.
  - `membership_id`, `file_number`, phones → strings (leading zeros stay).
  - Enum codes (transaction type/status/payment method, subscription payment status, equipment condition/status, inventory statuses, board role/status/priority, player student/worker) → `UiLang::get('<the key the screen uses for that code>', <raw code as default>)`. Find each screen key where the Vue page renders that enum (e.g. `grep -rn "payment_status\|statusLabel" resources/js/Pages/Subscriptions/Show.vue`). If no key exists for a code, add one via `i18n-add` with ar/fr/en.
  - Models with `HasLocalizedName` (category, wilaya, status, branch, finance category, job) → `->localized_name`. Subscriptions' category changes from `->name` to `->localized_name`.
  - Positions keep their abbreviations (GK, CB — universal codes; the players tests assert them).
  - Subscription TOTAL row label → `UiLang::get('col.total')`. Inventory result → `col.found` / `col.missing` / `-`.
  - Players export: append a `col.job` column at the end with the job's localized name (`member_job` relation — check its name in `app/Models/Player.php`, eager-load it).

- [ ] **Step 1: Write the failing test** — `tests/Feature/LocalizedExportsTest.php`. Build the fixtures with the factories the existing export tests use (copy their setup from `PlayerListActionsTest::export_returns_csv_with_the_player` and `TransactionTitleFlowTest`).

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class LocalizedExportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $locale): User
    {
        // SetLocale reads the `lang` cookie first, then users.preferred_lng.
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => $locale]);
    }

    private function sheet($response): string
    {
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, ob_get_clean());
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }

    public static function routes(): array
    {
        return [
            'players' => ['players.export', [], 'col.membership_id'],
            'transactions' => ['transactions.export', [], 'col.cash_register'],
            'catalogs' => ['equipment.catalogs.export', [], 'col.brand'],
            'board members' => ['board.members.export', [], 'col.term_start'],
            'board tasks' => ['board.tasks.export', [], 'col.due_date'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function exports_default_to_xlsx_with_headers_in_the_users_language(string $route, array $params, string $headerKey): void
    {
        foreach (['ar', 'fr'] as $locale) {
            $response = $this->actingAs($this->admin($locale))->get(route($route, $params));

            $response->assertOk();
            $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
            $this->assertStringContainsString(UiLang::get($headerKey, null, $locale), $this->sheet($response->baseResponse), "$route/$locale");
        }
    }

    #[Test]
    public function the_transactions_export_writes_localized_type_and_status_and_numeric_amounts(): void
    {
        $admin = $this->admin('fr');
        Transaction::create([
            'transaction_date' => '2026-01-15', 'transaction_type' => 'income', 'category' => 'other',
            'amount' => 1500, 'status' => 'Paid', 'payment_method' => 'cash', 'recorded_by_user_id' => $admin->id,
        ]);

        $xml = $this->sheet($this->actingAs($admin)->get(route('transactions.export'))->baseResponse);

        $this->assertStringContainsString(UiLang::get('income', null, 'fr'), $xml);
        $this->assertStringContainsString(UiLang::get('paid', null, 'fr'), $xml);
        $this->assertMatchesRegularExpression('#<v>1500</v>#', $xml);
        $this->assertMatchesRegularExpression('#<c r="A\d+" s="2"><v>46037</v>#', $xml); // 2026-01-15 as a date cell
    }

    #[Test]
    public function format_csv_still_returns_a_bom_csv(): void
    {
        $response = $this->actingAs($this->admin('ar'))->get(route('players.export', ['format' => 'csv']));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    #[Test]
    public function the_board_table_stays_pdf_by_default_and_offers_xlsx(): void
    {
        $admin = $this->admin('ar');
        $player = Player::factory()->create();

        $this->actingAs($admin)->get(route('players.board-table', ['category_id' => $player->category_id]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            UiLang::get('col.drawer', null, 'ar'),
            $this->sheet($this->actingAs($admin)->get(route('players.board-table', ['category_id' => $player->category_id, 'format' => 'xlsx']))->baseResponse),
        );
    }
}
```

Adjust the fixture lines to the real factories/columns (e.g. whether `Player::factory()` sets `category_id`, the real i18n keys for `income`/`paid`). Keep every assertion's intent.

- [ ] **Step 2: Run it — expect FAIL** (content type is `text/csv`).

- [ ] **Step 3: Convert the transactions export** (reference implementation — the others follow the same shape):

```php
    public function export(Request $request)
    {
        $query = Transaction::query()->with(['recordedBy', 'financeAccount', ...TransactionTitle::RELATIONS])->where('archived', false);
        $this->applyFilters($query, $request);

        $rows = $query->latest('transaction_date')->get()->map(fn (Transaction $t) => [
            $t->transaction_date,
            TransactionTitle::for($t),
            UiLang::get($t->transaction_type, $t->transaction_type),
            $t->financeCategory?->localized_name ?? $t->category,
            (float) $t->amount,
            UiLang::get(strtolower((string) $t->status), $t->status),
            UiLang::get((string) $t->payment_method, $t->payment_method),
            $t->financeAccount?->name,
            $t->description,
            $t->recordedBy?->name,
        ])->all();

        $headers = array_map(UiLang::get(...), [
            'col.date', 'col.title', 'col.type', 'col.category', 'col.amount', 'col.status',
            'col.payment_method', 'col.cash_register', 'col.description', 'col.recorded_by',
        ]);

        return Export::download(Export::format($request), 'transactions-'.now()->format('Y-m-d'), $headers, $rows, UiLang::get('transactions', 'Transactions'));
    }
```

(Replace the `UiLang::get(...)` value keys with the keys `resources/js/Pages/Transactions/Index.vue` really uses for type/status/payment method. Remove the `ExcelExporter` import and parameter.)

- [ ] **Step 4: Convert the other exports** following the rules above, one controller at a time, running `composer test` after each and updating the listed existing tests to request `['format' => 'csv']` where they read CSV text (their header assertions change from raw keys to the `en` labels only if they assert headers — keep them passing by asserting `UiLang::get('col.xxx')`).
  - `PlayerController::export`: headers `col.membership_id, col.file_number, col.wilaya, col.main_position, col.other_positions, col.full_name, col.category, col.status, col.player_type, col.join_year, col.debt, col.phones, col.branches, col.job`; debt `(float)`; `join_year` `(int)` when set; student/worker via the screen keys.
  - `SubscriptionController::export`: headers `#, col.membership_id, col.player_name, col.category, col.amount_owed, col.amount_paid, col.status`; `$rowNum` stays an int; category `localized_name`; status via screen key; TOTAL row label `col.total`; filter in the title translated via the filter keys the page uses.
  - `EquipmentCatalogController::export`: `col.name, col.category, col.brand, col.purchase_price, col.total_units, col.description`; price `(float)`, units `(int)`; category: localized name if `EquipmentCategory` has one, else raw.
  - `EquipmentItemController::export`: `col.serial, col.designation, col.condition, col.location, col.status, col.purchase_date, col.notes`; condition/status via screen keys; purchase date as Carbon.
  - `InventoryController::export`: `col.item, col.catalog, col.expected_status, col.expected_condition, col.expected_location, col.result, col.actual_condition, col.actual_location, col.note`; statuses/conditions via screen keys; result via `col.found` / `col.missing` / `-`.
  - `BoardController::exportMembers`: `col.name, col.role, col.email, col.phone, col.term_start, col.term_end, col.status`; dates as Carbon; role/status via screen keys. `exportTasks`: `col.title, col.assignee, col.meeting, col.priority, col.status, col.progress, col.due_date`; progress `(int)`.
  - `PlayerPrintController::boardTable`: after loading `$players`, if `in_array($request->query('format'), ['xlsx', 'csv'], true)` return `Export::download($request->query('format'), 'board-table-'.$category->id.'-'.$season->startYear, ['#', col.member, col.membership_id, col.file_number, col.drawer], $rows, $category->localized_name.' — '.$season->label())` with the same row values the Blade view prints (read `resources/views/pdf/board-table.blade.php` for them; use the season's display method that the view uses). Otherwise keep the PDF.

- [ ] **Step 5: Run `composer test` — all green. `npm run i18n:check` — clean.**

- [ ] **Step 6: Commit** (explicit list of every modified controller, test and catalog file)

```bash
git commit -m "feat(export): xlsx by default, localized headers and values on every export"
```

---

### Task 5: Transactions import/export round-trip

**Files:**
- Modify: `app/Http/Controllers/TransactionImportController.php`
- Test: `tests/Feature/TransactionImportRoundTripTest.php`; keep `tests/Feature/TransactionTitleFlowTest.php::the_import_reads_an_optional_title_column` green.

**Interfaces:**
- Consumes: `Spreadsheet::readRows()` (Task 2), `ImportColumns` (Task 3), `Export` (Task 1), the transactions export (Task 4).
- Produces: `TransactionImportController::COLUMNS` in the export's order, as `ImportColumns` definitions.

**Behaviour:**
- Canonical column order (template and export): Date, Title, Type, Category, Amount, Status, Payment method, Cash register, Description. The export's extra Recorded By column is ignored on import.
- Old files still import: legacy headers `Date (YYYY-MM-DD)`, `Type (income/expense)`, `Category`, `Amount`, `Status (Paid/Partial/Unpaid/Exempt)`, `Payment Method`, `Description`, `Title` map by header. A headerless old 8-column file cannot be told apart from a new one by position; the header row is what disambiguates — all templates ever shipped have one.
- Values: type via `ImportColumns::value($raw, ['income' => <key>, 'expense' => <key>])`, default `income`; status via `['Paid' => …, 'Partial' => …, 'Unpaid' => …, 'Exempt' => …]`, default `Paid`; payment method via the codes the transaction form offers (read `StoreTransactionRequest`), default `cash`.
- Category: match a `FinanceCategory` of the row's type by `NameNormalizer::key` over its localized names (all locales — check how `HasLocalizedName` stores them: `name`, `name_ar`, `name_fr`, `name_en`) → set `finance_category_id` and `category` = its slug/name as today's code expects; no match → today's behaviour (raw text, default `other`).
- Cash register: match a `FinanceAccount` by `NameNormalizer::key($account->name)` (plus `name_ar/fr/en` if the model has them) → `finance_account_id`. Blank cell → leave empty silently. Non-blank but no match → leave empty (the observer applies the default register) and add a row warning `__('Row :line: cash register ":name" not found, the default register was used.', …)` — add this string to `lang/{ar,fr}.json` like the other flash strings in this controller (check where `Row :line: :message` is translated and follow it).
- Row numbers in messages are the real spreadsheet row: `$headerRow + $offset + 2`.
- The file input accepts xlsx: validation `mimes:csv,txt,xlsx` plus the magic-byte check already in `Spreadsheet`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/TransactionImportRoundTripTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionImportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $locale = 'ar'): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => $locale]);
    }

    private function download(User $user, string $format): UploadedFile
    {
        $response = $this->actingAs($user)->get(route('transactions.export', ['format' => $format]));
        ob_start();
        $response->baseResponse->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'rt').'.'.$format;
        file_put_contents($path, ob_get_clean());

        return new UploadedFile($path, 'transactions.'.$format, null, null, true);
    }

    #[Test]
    public function an_exported_file_re_imports_with_the_same_values_in_both_formats(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            Transaction::query()->delete();
            $admin = $this->admin($format === 'xlsx' ? 'ar' : 'fr');
            $register = FinanceAccount::query()->first() ?? $this->markTestSkipped('needs a seeded register');
            Transaction::create([
                'transaction_date' => '2026-02-03', 'transaction_type' => 'expense', 'category' => 'other',
                'amount' => 750.5, 'status' => 'Partial', 'payment_method' => 'cash',
                'description' => 'فاتورة كهرباء', 'title' => 'Électricité', 'finance_account_id' => $register->id,
                'recorded_by_user_id' => $admin->id,
            ]);

            $file = $this->download($admin, $format);
            Transaction::query()->delete();

            $this->actingAs($admin)->post(route('transactions.import'), ['file' => $file])->assertSessionHasNoErrors();

            $t = Transaction::query()->sole();
            $this->assertSame('2026-02-03', $t->transaction_date->format('Y-m-d'), $format);
            $this->assertSame('expense', $t->transaction_type, $format);
            $this->assertSame(750.5, (float) $t->amount, $format);
            $this->assertSame('Partial', $t->status, $format);
            $this->assertSame('فاتورة كهرباء', $t->description, $format);
            $this->assertSame('Électricité', $t->title, $format);
            $this->assertSame($register->id, $t->finance_account_id, $format);
        }
    }

    #[Test]
    public function an_unknown_cash_register_falls_back_to_the_default_with_a_warning(): void
    {
        $admin = $this->admin('en');
        $path = tempnam(sys_get_temp_dir(), 'rt').'.csv';
        file_put_contents($path, "\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n2026-02-03,X,income,other,100,Paid,cash,Nowhere Box,\r\n");

        $this->actingAs($admin)->post(route('transactions.import'), ['file' => new UploadedFile($path, 't.csv', 'text/csv', null, true)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Nowhere Box'));

        $this->assertNotNull(Transaction::query()->sole()->finance_account_id);
    }

    #[Test]
    public function a_file_made_from_the_old_template_still_imports(): void
    {
        $admin = $this->admin('en');
        $path = tempnam(sys_get_temp_dir(), 'rt').'.csv';
        file_put_contents($path, "\xEF\xBB\xBFDate (YYYY-MM-DD),Type (income/expense),Category,Amount,Status (Paid/Partial/Unpaid/Exempt),Payment Method,Description,Title\r\n2026-01-15,expense,other,300,Unpaid,cash,desc,Old title\r\n");

        $this->actingAs($admin)->post(route('transactions.import'), ['file' => new UploadedFile($path, 't.csv', 'text/csv', null, true)]);

        $t = Transaction::query()->sole();
        $this->assertSame('expense', $t->transaction_type);
        $this->assertSame(300.0, (float) $t->amount);
        $this->assertSame('Unpaid', $t->status);
        $this->assertSame('Old title', $t->title);
    }
}
```

(Adjust: the import route name — check `routes/web.php` near line 177; how a register is created in tests — look at an existing finance test for a factory/provisioner; whether the session error key is `error`.)

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Rewrite `TransactionImportController`** — `COLUMNS` as `ImportColumns` definitions (with `legacy` headers above), `store()` reads with `Spreadsheet::readRows()`, locates with `ImportColumns::locate()`, iterates rows after the header row, maps values as described, collects warnings alongside errors. `template()` is rewritten in Task 6.

- [ ] **Step 4: Run the new test, `TransactionTitleFlowTest`, then `composer test` — all green.**

- [ ] **Step 5: Commit** — `git commit -m "fix(transactions): export and import share one column order; import reads xlsx, localized values and the cash register"`

---

### Task 6: Localized import templates + header-aware importers (players, equipment)

**Files:**
- Modify: `app/Http/Controllers/PlayerImportController.php` (`COLUMNS`, `template`, `store`, `mapRow`), `app/Http/Controllers/EquipmentCatalogController.php` (`IMPORT_COLUMNS`, `importTemplate`, `import`, `mapImportRow`), `app/Http/Controllers/EquipmentItemController.php` (`IMPORT_COLUMNS`, `importTemplate`, `import`, `mapImportRow`), `app/Http/Controllers/TransactionImportController.php` (`template`)
- Test: `tests/Feature/ImportTemplatesTest.php`; existing import tests stay green unchanged (they are the backward-compatibility proof).

**Interfaces:**
- Consumes: `Export`, `Spreadsheet`, `ImportColumns`, `col.*` + `col.hint.*` keys.
- Produces: each template route accepts `?format=xlsx|csv` (default xlsx), with headers in the user's language.

**Behaviour:**
- Template header = `UiLang::get(label)` plus, where today's header carries a hint, ` (`.UiLang::get(hint).`)`: birthdate/purchase_date/transaction date → `col.hint.date`; wilaya → `col.hint.code_or_name`; position → `col.hint.abbr_or_name`; other_positions → `col.hint.comma_separated`; skill_level → `col.hint.one_to_ten`. `ImportColumns` strips the hint when matching, so it must build the template headers itself: add `ImportColumns::headers()` support for an optional `hint` key in the column definition (`['key'=>…, 'label'=>…, 'hint'=>'col.hint.date', 'legacy'=>[…]]`) and a unit test for it in `ImportColumnsTest`.
- Every column keeps today's Arabic/English header in `legacy`, and today's order (so headerless positional files still import).
- Example row values: localized where the importer accepts localized values (gender, student/worker, condition, transaction type/status); keep codes like `GK`, `47`, `O+`.
- Importers accept localized enum values via `ImportColumns::value()`: players gender (`Male`/`Female` + screen keys) and status (`student`/`worker`); items condition (`New/Good/Fair/Poor/Damaged` + screen keys). Defaults unchanged.
- Importers skip the export title/spacer rows (via `locate()`), and error row numbers are the real spreadsheet rows.
- Validation `mimes:csv,txt,xlsx`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ImportTemplatesTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $locale): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => $locale]);
    }

    private function save($response, string $ext): UploadedFile
    {
        ob_start();
        $response->baseResponse->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'tp').'.'.$ext;
        file_put_contents($path, ob_get_clean());

        return new UploadedFile($path, 'template.'.$ext, null, null, true);
    }

    public static function templates(): array
    {
        return [
            ['players.import.template', 'col.firstname'],
            ['transactions.import.template', 'col.cash_register'],
            ['equipment.catalogs.import.template', 'col.brand'],
            ['equipment.items.import.template', 'col.designation'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function templates_default_to_xlsx_with_headers_in_the_users_language(string $route, string $key): void
    {
        $response = $this->actingAs($this->user('fr'))->get(route($route));

        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = \App\Support\Spreadsheet::readRows($this->save($response, 'xlsx')->getRealPath());
        $this->assertContains(UiLang::get($key, null, 'fr'), array_map(fn ($h) => preg_replace('/\s*\(.*\)$/u', '', (string) $h), $rows[0]));
    }

    #[Test]
    public function a_players_template_filled_in_french_imports_for_an_arabic_user(): void
    {
        $fr = $this->user('fr');
        $file = $this->save($this->actingAs($fr)->get(route('players.import.template', ['format' => 'csv'])), 'csv');

        $this->actingAs($this->user('ar'))->post(route('players.import'), ['file' => $file]);

        $this->assertSame(1, Player::query()->count()); // the example row
    }

    #[Test]
    public function the_players_import_reads_an_xlsx_with_columns_in_another_order(): void
    {
        $headers = [UiLang::get('col.lastname', null, 'ar'), UiLang::get('col.firstname', null, 'ar'), UiLang::get('col.gender', null, 'ar')];
        $bytes = \App\Support\Export\XlsxWriter::build($headers, [['بن علي', 'محمد', UiLang::get('female', null, 'ar')]], null, true);
        $path = tempnam(sys_get_temp_dir(), 'tp').'.xlsx';
        file_put_contents($path, $bytes);

        $this->actingAs($this->user('ar'))->post(route('players.import'), ['file' => new UploadedFile($path, 'p.xlsx', null, null, true)]);

        $p = Player::query()->sole();
        $this->assertSame('محمد', $p->firstname);
        $this->assertSame('بن علي', $p->lastname);
        $this->assertSame('Female', $p->gender);
    }
}
```

(Adjust the route names for the import POSTs, the gender label key, and anything the player import needs to create a row — e.g. a category — using the setup from `PlayerImportTest::it_imports_players_without_attaching_subscriptions`.)

- [ ] **Step 2: Run it — expect FAIL.**
- [ ] **Step 3: Implement** the four templates and three importers as described. Keep `COLUMNS` order and the "appended last" comments.
- [ ] **Step 4: Run the new test and every existing import test** (`--filter="Import|Wilaya|Positions|JobLocalization|CategoryLocalization"`), then `composer test` — all green; `npm run i18n:check` clean.
- [ ] **Step 5: Commit** — `git commit -m "feat(import): localized xlsx/csv templates; importers match headers and values in ar/fr/en"`

---

### Task 7: ExportMenu.vue, xlsx upload everywhere, drop SheetJS and the old helpers

**Files:**
- Create: `resources/js/Components/ExportMenu.vue`
- Modify: `resources/js/Pages/Players/Index.vue` (BOM; export + template + import input + remove SheetJS conversion at :148-170), `resources/js/Pages/Subscriptions/Show.vue` (:87-88, :141-148 dead Alpine wrapper, :250), `resources/js/Pages/Transactions/Index.vue` (:54, :99, :101), `resources/js/Pages/Equipment/Catalog/Index.vue` (:66-83, :109, :235, :243), `resources/js/Pages/Equipment/Catalog/Show.vue` (:355-372, :405, :764, :772), `resources/js/Pages/Equipment/Inventory/Session.vue` (:137), `resources/js/Pages/Board/Members.vue` (:148), `resources/js/Pages/Board/Tasks.vue` (:215), the board-table link in `Players/Index.vue` (:263)
- Modify: `package.json` / `package-lock.json` (`npm uninstall xlsx`)
- Delete: `app/Services/Export/ExcelExporter.php`, `app/Support/Csv.php` — after `grep -rn "ExcelExporter\|Support\\\\Csv\|Csv::" app tests routes` shows no users (move any left to `Export`/`Spreadsheet`).
- Test: `tests/Feature/ExportMenuWiringTest.php` (server side) + manual browser check.

**Interfaces:**
- Consumes: routes with `?format=` (Tasks 4–6).
- Produces: `<ExportMenu :href="route('players.export', filters)" :label="t('export')" :formats="['xlsx','csv']" />` — props: `href: String` (required, URL without `format`), `label: String` (required), `formats: Array` (default `['xlsx','csv']`; board table passes `['pdf','xlsx','csv']`), `align: String` (default `'right'`). Renders the existing `Dropdown.vue` with plain `<a :href download>` items (`role="menuitem"`), one per format, labels `t('export.format.xlsx')` = "Excel (.xlsx)", `t('export.format.csv')` = "CSV", `t('export.format.pdf')` = "PDF"; the first format is the default and is listed first.

- [ ] **Step 1: Add the i18n keys** via `i18n-add`: `export.format.xlsx` (ar "Excel (.xlsx)", fr "Excel (.xlsx)", en "Excel (.xlsx)"), `export.format.csv` ("CSV" ×3), `export.format.pdf` ("PDF" ×3). Also reuse existing `export` / `download_template` keys — grep before adding.
- [ ] **Step 2: Write `ExportMenu.vue`**

```vue
<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Components/Dropdown.vue';

const props = defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
    formats: { type: Array, default: () => ['xlsx', 'csv'] },
    align: { type: String, default: 'right' },
});

const { t } = useI18n();

const items = computed(() => props.formats.map((format) => {
    const url = new URL(props.href, window.location.origin);
    url.searchParams.set('format', format);
    return { format, url: url.pathname + url.search, label: t(`export.format.${format}`) };
}));
</script>

<template>
    <Dropdown :align="align" width="48">
        <template #trigger>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800" aria-haspopup="menu">
                <slot name="icon" />
                {{ label }}
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </button>
        </template>
        <template #content>
            <a v-for="item in items" :key="item.format" :href="item.url" role="menuitem"
               class="block w-full px-4 py-2 text-start text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700">
                {{ item.label }}
            </a>
        </template>
    </Dropdown>
</template>
```

(Trigger classes = the export button at `Board/Members.vue:148` today. PDF links in the board-table case keep `target="_blank"`: add `:target="item.format === 'pdf' ? '_blank' : null"`.)

- [ ] **Step 3: Replace each export button and template link** with `ExportMenu`, keeping the current filter query in `href`. In `Players/Index.vue` the export and template are in the `secondaryActions` array rendered inline and in a Dropdown: replace each of those two entries with a separate `ExportMenu` in the toolbar (keep the rest of the array). In `Subscriptions/Show.vue` delete the dead `x-data` wrapper.
- [ ] **Step 4: Import inputs.** Set `accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"` on all four inputs (players, transactions, catalogs, items); delete the SheetJS conversion functions and their `import('xlsx')` calls and post the picked file as is. Then `npm uninstall xlsx`.
- [ ] **Step 5: Server wiring test** — `tests/Feature/ExportMenuWiringTest.php` asserts, for each export and template route, that `?format=csv` returns `text/csv; charset=UTF-8` and no `format` returns the xlsx content type (one data-provider test; reuse the admin helper). Run it — PASS.
- [ ] **Step 6: Delete `ExcelExporter` and `Csv`** once grep shows no users; `composer test` — green.
- [ ] **Step 7: Build + offline check**

```bash
npm run build
grep -rlE "import\(['\"]xlsx|sheetjs" public/build/assets || echo "no sheetjs"
grep -rhoE "https?://[a-zA-Z0-9.-]+" public/build/assets | sort -u
```

Expected: `no sheetjs`; the host list contains only XML-namespace hosts (`www.w3.org`) or none — no CDN.

- [ ] **Step 8: Browser check** at `http://127.0.0.1:2026` in ar and fr: each menu opens, each format downloads, a template round-trips through its import. (The owner uses IDM, which captures downloads; a stale same-named file in Downloads is not a bug.)
- [ ] **Step 9: Commit** (explicit files, including `package.json`, `package-lock.json`, deleted files via `git rm`) — `git commit -m "feat(ui): ExportMenu with xlsx/csv on every export and template; server reads xlsx uploads, SheetJS removed"`

---

### Task 8: Remove PhpSpreadsheet, re-apply the desktop patch, docs

**Files:**
- Modify: `composer.json`, `composer.lock`, `README.md:15`
- Check: `vendor/nativephp/desktop/resources/electron/electron-builder.mjs` (afterPack patch)

- [ ] **Step 1:** `grep -rn "PhpOffice" app routes resources tests config` → no output.
- [ ] **Step 2:** `composer remove phpoffice/phpspreadsheet` (PowerShell).
- [ ] **Step 3: Re-apply the afterPack patch** — composer reverts it:

```bash
grep -c "vcruntime140" vendor/nativephp/desktop/resources/electron/electron-builder.mjs
```

If the count is 0, copy `C:\Users\MUSTAPHA\AppData\Local\Temp\claude\d--irnb-laravel\26e9e374-0040-4c34-bba5-60b377f4076c\scratchpad\electron-builder.mjs.patched` over it, then re-run the grep (count ≥ 1).
- [ ] **Step 4:** Update `README.md:15`: exports and templates are .xlsx (default) or CSV, written by the in-house `App\Support\Export\XlsxWriter`; imports read .xlsx and CSV (UTF-8 or Windows-1256) on the server.
- [ ] **Step 5:** `composer test` — green. Desktop smoke script from Tasks 1–2 with the bundled `php.exe` — `OK` and intact Arabic (this also exercises Composer's platform check without PhpSpreadsheet's ext requirements).
- [ ] **Step 6: Commit** — `git add composer.json composer.lock README.md` — `git commit -m "chore: drop unused phpoffice/phpspreadsheet"`

---

### Task 9: Verification and deploy notes

- [ ] `composer test` — record the count in the ledger.
- [ ] `npm run i18n:check` — clean. `npm run build` — succeeds; offline grep from Task 7 Step 7 — clean.
- [ ] Pint on the changed PHP files only: `vendor/bin/pint --test <files>`.
- [ ] Desktop check with the bundled `php.exe`: run `php.exe artisan test --filter="XlsxWriterTest|SpreadsheetReaderTest|ExportDownloadTest"` if the bundled PHP can run the suite; else the smoke script.
- [ ] Deploy notes (append to the ledger):
  - No migrations in P4 → no seed refresh needed for schema; rebuild the desktop app (new PHP classes, new JS bundle, self-hosted fonts from `fix/offline-fonts`).
  - Re-check the afterPack patch before `native:build`.
  - Ask the owner how they opened the CSV that looked garbled (double-click vs Data → From Text) and confirm the .xlsx default fixes it.
  - Browser QA in ar and fr: every ExportMenu, every template → import round-trip, an Excel "CSV (ANSI)" file, and the board table in PDF/xlsx.
