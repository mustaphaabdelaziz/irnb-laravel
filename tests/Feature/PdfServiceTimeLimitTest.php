<?php

namespace Tests\Feature;

use App\Services\Pdf\PdfService;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class PdfServiceTimeLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        set_time_limit(0);

        parent::tearDown();
    }

    /** Lays out through PdfService and returns the time limit in force during the layout. */
    private function limitWhileLayingOut(): string
    {
        $mpdf = new class(['tempDir' => storage_path('app/mpdf')]) extends Mpdf
        {
            public string $limit = '';

            public function WriteHTML($html, $mode = 0, $init = true, $close = true)
            {
                $this->limit = ini_get('max_execution_time');
            }
        };

        (new ReflectionMethod(PdfService::class, 'write'))->invoke(app(PdfService::class), $mpdf, '<p>x</p>');

        return $mpdf->limit;
    }

    #[Test]
    public function a_long_layout_outlives_the_request_time_limit_which_then_returns(): void
    {
        // The desktop server (php -S) stops a request at 30 s of wall-clock time on Windows.
        set_time_limit(30);

        $this->assertGreaterThan(30, (int) $this->limitWhileLayingOut());
        $this->assertSame('30', ini_get('max_execution_time'));
    }

    #[Test]
    public function a_process_without_a_time_limit_never_gains_one(): void
    {
        // A queue worker that rendered a PDF must not be killed minutes later.
        set_time_limit(0);

        $this->assertSame('0', $this->limitWhileLayingOut());
        $this->assertSame('0', ini_get('max_execution_time'));
    }
}
