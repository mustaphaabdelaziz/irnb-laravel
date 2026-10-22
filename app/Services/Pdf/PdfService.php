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
    /** @param  array<string, mixed>  $config  mPDF options over the A4 defaults. */
    private function make(bool $rtl, bool $landscape = false, array $config = []): Mpdf
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
            ...$config,
        ]);

        return $mpdf;
    }

    /**
     * mPDF runs the whole HTML string through a PCRE pass in WriteHTML();
     * past pcre.backtrack_limit (1,000,000 bytes by default) it throws
     * MpdfException instead of rendering. A month sheet with ~300 players
     * and ~20 sessions is already over that. Raise the limit for this
     * render only, then restore it so other PCRE users in the same
     * process (e.g. a queue worker) are unaffected either way.
     */
    private function write(Mpdf $mpdf, string $html): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', (string) max((int) $previousLimit, strlen($html) * 2));
            $mpdf->WriteHTML($html);
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }
    }

    public function render(string $html, bool $rtl = true, bool $landscape = false): string
    {
        $mpdf = $this->make($rtl, $landscape);

        $this->write($mpdf, $html);

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

    /**
     * One page on roll paper (thermal printer): $widthMm wide and exactly as
     * tall as the content, so the printer feeds no blank paper. mPDF cannot
     * size a page to its content, so the HTML is laid out once on a tall page
     * to measure it, then again on a page cut to that height.
     */
    public function renderRoll(string $html, bool $rtl = true, float $widthMm = 80): string
    {
        $margins = ['margin_top' => 3, 'margin_bottom' => 3, 'margin_left' => 4, 'margin_right' => 4, 'margin_header' => 0, 'margin_footer' => 0];

        $probe = $this->make($rtl, false, ['format' => [$widthMm, 1000], ...$margins]);
        $this->write($probe, $html);
        $height = max(40, ceil($probe->y) + $margins['margin_bottom'] + 2);

        $mpdf = $this->make($rtl, false, ['format' => [$widthMm, $height], ...$margins]);
        // A rounding millimetre must never spill onto a second ticket.
        $mpdf->SetAutoPageBreak(false);
        $this->write($mpdf, $html);

        return $mpdf->Output('', 'S');
    }

    public function streamRoll(string $html, string $filename, bool $rtl = true, float $widthMm = 80): Response
    {
        return response($this->renderRoll($html, $rtl, $widthMm), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
