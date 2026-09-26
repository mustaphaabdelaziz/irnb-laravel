<?php

namespace Tests\Unit;

use App\Support\Import\XlsxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class XlsxReaderTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    protected function tearDown(): void
    {
        XlsxReader::$maxPartBytes = XlsxReader::MAX_PART_BYTES;
        parent::tearDown();
    }

    /** @param  array<string, string>  $parts */
    private function xlsx(string $sheetData, array $parts = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xr').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="'.self::NS.'"><sheetData>'.$sheetData.'</sheetData></worksheet>');
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        return $path;
    }

    #[Test]
    public function the_production_caps_are_generous_but_bounded(): void
    {
        $this->assertSame(50 * 1024 * 1024, XlsxReader::MAX_PART_BYTES);
        $this->assertSame(XlsxReader::MAX_PART_BYTES, XlsxReader::$maxPartBytes);
        $this->assertSame(100000, XlsxReader::MAX_ROWS);
    }

    #[Test]
    public function an_oversized_shared_strings_part_is_refused_before_it_is_inflated(): void
    {
        XlsxReader::$maxPartBytes = 64 * 1024;
        $huge = '<sst xmlns="'.self::NS.'"><si><t>'.str_repeat('a', 70 * 1024).'</t></si></sst>';
        $path = $this->xlsx('<row r="1"><c r="A1" t="s"><v>0</v></c></row>', ['xl/sharedStrings.xml' => $huge]);

        $this->assertLessThan(8 * 1024, filesize($path), 'the file itself is small');
        $this->expectException(RuntimeException::class);
        XlsxReader::read($path);
    }

    #[Test]
    public function an_oversized_worksheet_is_refused(): void
    {
        XlsxReader::$maxPartBytes = 64 * 1024;
        $path = $this->xlsx(str_repeat('<row><c t="inlineStr"><is><t>xxxxxxxxxx</t></is></c></row>', 2000));

        $this->expectException(RuntimeException::class);
        XlsxReader::read($path);
    }

    #[Test]
    public function an_oversized_styles_part_is_refused(): void
    {
        XlsxReader::$maxPartBytes = 64 * 1024;
        $styles = '<styleSheet xmlns="'.self::NS.'"><cellXfs>'.str_repeat('<xf numFmtId="0"/>', 5000).'</cellXfs></styleSheet>';
        $path = $this->xlsx('<row r="1"><c r="A1"><v>1</v></c></row>', ['xl/styles.xml' => $styles]);

        $this->expectException(RuntimeException::class);
        XlsxReader::read($path);
    }

    #[Test]
    public function parts_under_the_cap_still_read(): void
    {
        XlsxReader::$maxPartBytes = 64 * 1024;
        $path = $this->xlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row>');

        $this->assertSame([['ok']], XlsxReader::read($path));
    }

    #[Test]
    public function a_huge_row_number_is_refused_instead_of_padding_empty_rows(): void
    {
        $path = $this->xlsx('<row r="1"><c r="A1"><v>1</v></c></row><row r="50000000"><c r="A50000000"><v>2</v></c></row>');

        $this->expectException(RuntimeException::class);
        XlsxReader::read($path);
    }

    #[Test]
    public function the_last_allowed_row_number_still_reads(): void
    {
        $r = XlsxReader::MAX_ROWS;
        $rows = XlsxReader::read($this->xlsx('<row r="'.$r.'"><c r="A'.$r.'"><v>2</v></c></row>'));

        $this->assertCount($r, $rows);
        $this->assertSame(['2'], $rows[$r - 1]);
    }

    #[Test]
    public function a_huge_column_reference_is_refused(): void
    {
        $path = $this->xlsx('<row r="1"><c r="ZZZZZZ1"><v>1</v></c></row>');

        $this->expectException(RuntimeException::class);
        XlsxReader::read($path);
    }

    private function readWithFormat(string $formatCode): ?string
    {
        $styles = '<styleSheet xmlns="'.self::NS.'"><numFmts count="1"><numFmt numFmtId="164" formatCode="'
            .htmlspecialchars($formatCode, ENT_QUOTES | ENT_XML1).'"/></numFmts>'
            .'<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>';
        $path = $this->xlsx('<row r="1"><c r="A1" s="1"><v>45000</v></c></row>', ['xl/styles.xml' => $styles]);

        return XlsxReader::read($path)[0][0];
    }

    public static function numberFormats(): array
    {
        return [
            'currency with escaped DA' => ['#,##0.00\ \D\A', false],
            'accounting padding and quoted DA' => ['_-* #,##0.00\ "DA"_-', false],
            'accounting parentheses' => ['_(* #,##0.00_);_(* \(#,##0.00\);_(* "-"??_);_(@_)', false],
            'plain decimal' => ['0.00', false],
            'dd/mm/yyyy' => ['dd/mm/yyyy', true],
            'yyyy-mm-dd' => ['yyyy-mm-dd', true],
            'd-mmm-yy' => ['d-mmm-yy', true],
            'localised date' => ['[$-40C]dd/mm/yyyy', true],
        ];
    }

    #[Test]
    #[DataProvider('numberFormats')]
    public function only_real_date_formats_turn_numbers_into_dates(string $format, bool $isDate): void
    {
        $this->assertSame($isDate ? '2023-03-15' : '45000', $this->readWithFormat($format), $format);
    }
}
