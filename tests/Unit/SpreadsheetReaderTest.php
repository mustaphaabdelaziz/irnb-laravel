<?php

namespace Tests\Unit;

use App\Support\Export\CsvWriter;
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
        // CREATE, not OVERWRITE: tempnam() only created the extension-less
        // base path; this ".xlsx" path doesn't exist yet, and OVERWRITE
        // fails (ER_NOENT) on a path that isn't there to overwrite.
        $zip->open($path, \ZipArchive::CREATE);
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
        // "الاسم,Prénom\r\nمحمد,éric\r\n" in Windows-1256.
        // Lowercase "éric": genuine Windows-1256 has no code point for
        // uppercase Latin-accented letters (É/È/À/Â all fail to encode),
        // only the lowercase ones (é/è/à/â/ê/î/ô/û/ç/ù) — confirmed with
        // iconv('UTF-8', 'CP1256', ...) directly, not just this codebase.
        $cp1256 = iconv('UTF-8', 'CP1256', "الاسم,Prénom\r\nمحمد,éric\r\n");
        $rows = Spreadsheet::readRows($this->file($cp1256, 'csv'));

        $this->assertSame(['الاسم', 'Prénom'], $rows[0]);
        $this->assertSame(['محمد', 'éric'], $rows[1]);
    }

    #[Test]
    public function a_utf8_csv_with_bom_and_semicolons_still_reads(): void
    {
        $rows = Spreadsheet::readRows($this->file("\xEF\xBB\xBFa;b\r\nمحمد;2\r\n", 'csv'));

        $this->assertSame(['a', 'b'], $rows[0]);
        $this->assertSame(['محمد', '2'], $rows[1]);
    }

    #[Test]
    public function re_importing_an_exported_csv_strips_the_writers_protective_apostrophe(): void
    {
        ob_start();
        CsvWriter::stream(['h'], [['+213-555-12-34', '=SUM(1)', '@at', "\tTab", "\rCr", "'plain", '-5', "O'Brien"]], null);
        $csv = (string) ob_get_clean();

        $rows = Spreadsheet::readRows($this->file($csv, 'csv'));

        // The first six were escaped by CsvWriter (=, @, +, -, tab, CR) and come back clean; a
        // genuine leading apostrophe not followed by one of those, or one elsewhere, is untouched.
        $this->assertSame(['+213-555-12-34', '=SUM(1)', '@at', "\tTab", "\rCr", "'plain", '-5', "O'Brien"], $rows[1]);
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
