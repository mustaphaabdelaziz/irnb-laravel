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
    private function make(bool $rtl, bool $landscape = false): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        return $mpdf;
    }

    public function render(string $html, bool $rtl = true, bool $landscape = false): string
    {
        $mpdf = $this->make($rtl, $landscape);

        // mPDF runs the whole HTML string through a PCRE pass in WriteHTML();
        // past pcre.backtrack_limit (1,000,000 bytes by default) it throws
        // MpdfException instead of rendering. A month sheet with ~300 players
        // and ~20 sessions is already over that. Raise the limit for this
        // render only, then restore it so other PCRE users in the same
        // process (e.g. a queue worker) are unaffected either way.
        $previousLimit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', (string) max((int) $previousLimit, strlen($html) * 2));
            $mpdf->WriteHTML($html);
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }

        // Collapsing the whitespace between tags (Blade's indentation,
        // mainly inside <td> cells) would shrink the HTML further, but at
        // least one PDF view relies on a literal space between two tags to
        // separate inline content (the month sheet legend's status marker
        // and its label, one span followed by a lone space then a <b>).
        // Collapsing '>\s+<' blindly across every PDF view in the app would
        // silently glue such pairs together, so it's left alone; raising
        // the backtrack limit above is enough on its own.
        return $mpdf->Output('', 'S');
    }

    public function stream(string $html, string $filename, bool $rtl = true, bool $landscape = false): Response
    {
        return response($this->render($html, $rtl, $landscape), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
