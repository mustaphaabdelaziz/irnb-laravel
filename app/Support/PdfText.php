<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** Free text for the PDFs: keeps Latin lines left-to-right inside right-to-left pages. */
final class PdfText
{
    /**
     * Each line without Arabic letters is wrapped in its own `<bdo dir="ltr">`.
     * Line breaks stay outside the bdo: mPDF does not grow a row for a `<br>` inside it.
     */
    public static function ltrIfLatin(?string $text): HtmlString
    {
        return new HtmlString(implode('<br />', array_map(
            fn (string $line) => preg_match('/\p{Arabic}/u', $line) === 0 ? '<bdo dir="ltr">'.e($line).'</bdo>' : e($line),
            preg_split('/\r\n|\r|\n/', (string) $text)
        )));
    }
}
