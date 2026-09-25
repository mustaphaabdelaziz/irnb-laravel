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
