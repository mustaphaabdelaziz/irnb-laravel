<?php

namespace App\Services\Competitions;

/**
 * The line-up pitch as standalone SVG for the PDF (mPDF renders it as an
 * image). Only shirt numbers are drawn inside it — names go in the table
 * below, so no Arabic text has to be shaped inside the SVG.
 */
final class PitchDrawing
{
    /** @param  list<array{x: int, y: int, label: string}>  $dots  x/y in 0–100, y from our own goal */
    public static function svg(string $sport, array $dots): string
    {
        $football = in_array($sport, ['football', 'futsal'], true);
        $surface = $football ? '#16a34a' : '#fde68a';
        $lines = $football
            ? '<rect x="2" y="2" width="64" height="101"/><line x1="2" y1="52.5" x2="66" y2="52.5"/><circle cx="34" cy="52.5" r="9.15"/>'
                .'<rect x="13.85" y="2" width="40.3" height="16.5"/><rect x="13.85" y="86.5" width="40.3" height="16.5"/>'
            : '<rect x="2" y="2" width="64" height="101"/><line x1="2" y1="52.5" x2="66" y2="52.5"/><circle cx="34" cy="52.5" r="6"/>';

        $marks = '';
        foreach ($dots as $dot) {
            $cx = round($dot['x'] * 68 / 100, 2);
            $cy = round((100 - $dot['y']) * 105 / 100, 2);
            $label = htmlspecialchars($dot['label'], ENT_XML1);
            $marks .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"3.4\" fill=\"#ffffff\" stroke=\"#0f172a\" stroke-width=\"0.5\"/>"
                ."<text x=\"{$cx}\" y=\"".($cy + 1.3)."\" font-size=\"3.6\" font-family=\"sans-serif\" font-weight=\"bold\" text-anchor=\"middle\" fill=\"#0f172a\">{$label}</text>";
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 68 105" width="68mm" height="105mm">'
            ."<rect x=\"0\" y=\"0\" width=\"68\" height=\"105\" fill=\"{$surface}\"/>"
            ."<g fill=\"none\" stroke=\"#ffffff\" stroke-width=\"0.4\">{$lines}</g>{$marks}</svg>";
    }
}
