<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin wrapper around mPDF. mPDF is used (over dompdf) because it reshapes and
 * bidi-orders Arabic text correctly, which the club's RTL documents require.
 */
class PdfService
{
    private function make(bool $rtl): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        return $mpdf;
    }

    public function render(string $html, bool $rtl = true): string
    {
        $mpdf = $this->make($rtl);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public function stream(string $html, string $filename, bool $rtl = true): Response
    {
        return response($this->render($html, $rtl), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
