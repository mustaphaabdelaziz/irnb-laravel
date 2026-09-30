<?php

namespace Tests\Unit;

use App\Support\Export\CsvWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CsvWriterTest extends TestCase
{
    /** Runs stream() and returns the bytes it wrote to php://output. */
    private function capture(array $headers, iterable $rows, ?string $title = null): string
    {
        ob_start();
        CsvWriter::stream($headers, $rows, $title);

        return (string) ob_get_clean();
    }

    /** Strips the BOM and the header line every capture starts with, so tests can look only at the data rows. */
    private function dataRow(string $body): string
    {
        $afterBom = substr($body, strlen(CsvWriter::BOM));

        return substr($afterBom, strpos($afterBom, "\r\n") + 2);
    }

    #[Test]
    public function a_formula_string_is_prefixed_with_an_apostrophe(): void
    {
        $body = $this->capture(['h'], [['=HYPERLINK("x")']]);

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"\r\n", $this->dataRow($body));
    }

    #[Test]
    public function an_at_sign_formula_string_is_prefixed(): void
    {
        $body = $this->capture(['h'], [['@SUM(1)']]);

        $this->assertSame("'@SUM(1)\r\n", $this->dataRow($body));
    }

    #[Test]
    public function a_tab_or_cr_led_string_is_prefixed(): void
    {
        $body = $this->capture(['h'], [["\tcmd"], ["\rcmd"]]);

        $this->assertStringContainsString("'\tcmd", $body);
        $this->assertStringContainsString("'\rcmd", $body);
    }

    #[Test]
    public function a_phone_number_like_string_is_unchanged(): void
    {
        $body = $this->capture(['h'], [['+213 555 12 34']]);

        // fputcsv quotes a field with an embedded space; the content itself stays unprefixed.
        $this->assertSame("\"+213 555 12 34\"\r\n", $this->dataRow($body));
    }

    #[Test]
    public function a_negative_number_like_string_is_unchanged(): void
    {
        $body = $this->capture(['h'], [['-5']]);

        $this->assertSame("-5\r\n", $this->dataRow($body));
    }

    #[Test]
    public function an_int_is_unchanged_whatever_its_sign(): void
    {
        $body = $this->capture(['h'], [[-5]]);

        $this->assertSame("-5\r\n", $this->dataRow($body));
    }

    #[Test]
    public function a_plain_string_is_unchanged(): void
    {
        $body = $this->capture(['h'], [['ok']]);

        $this->assertSame("ok\r\n", $this->dataRow($body));
    }

    #[Test]
    public function a_leading_sign_string_that_is_not_numeric_is_prefixed(): void
    {
        $body = $this->capture(['h'], [['-cmd|calc']]);

        $this->assertSame("'-cmd|calc\r\n", $this->dataRow($body));
        $this->assertSame("'+HYPERLINK(1)\r\n", $this->dataRow($this->capture(['h'], [['+HYPERLINK(1)']])));
    }

    #[Test]
    public function a_header_or_title_cell_that_looks_like_a_formula_is_prefixed_too(): void
    {
        $body = $this->capture(['=cmd'], [['ok']], '@title');

        $this->assertStringContainsString("'@title\r\n", $body);
        $this->assertStringContainsString("'=cmd\r\n", $body);
    }
}
