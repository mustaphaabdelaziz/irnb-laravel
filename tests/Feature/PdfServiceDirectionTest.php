<?php

namespace Tests\Feature;

use App\Services\Pdf\PdfService;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class PdfServiceDirectionTest extends TestCase
{
    private function document(?bool $rtl): Mpdf
    {
        return (new ReflectionMethod(PdfService::class, 'make'))->invoke(app(PdfService::class), $rtl);
    }

    #[Test]
    public function arabic_pdfs_are_laid_out_right_to_left_by_default(): void
    {
        app()->setLocale('ar');
        $mpdf = $this->document(null);

        // The directionality flag alone left text and tables aligned left; the default alignment is what lays the page out.
        $this->assertSame('rtl', $mpdf->directionality);
        $this->assertSame('R', $mpdf->defaultAlign);
        $this->assertSame('R', $mpdf->defaultTableAlign);
    }

    #[Test]
    public function french_and_english_pdfs_stay_left_to_right_by_default(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('L', $this->document(null)->defaultAlign, $locale);
        }
    }

    #[Test]
    public function an_explicit_direction_wins_over_the_language(): void
    {
        app()->setLocale('ar');
        $this->assertSame('L', $this->document(false)->defaultAlign);

        app()->setLocale('fr');
        $this->assertSame('R', $this->document(true)->defaultAlign);
    }
}
