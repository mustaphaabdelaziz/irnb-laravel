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
