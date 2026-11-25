<?php

namespace App\Support;

use NumberFormatter;

/**
 * An amount written out, as payment and transfer orders require
 * ("quarante-cinq mille … dinars"). intl's spell-out rules per locale; the
 * desktop's bundled PHP ships intl too.
 */
final class AmountInWords
{
    public static function format(float $amount, string $locale): string
    {
        $amount = round($amount, 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);
        $speller = new NumberFormatter($locale, NumberFormatter::SPELLOUT);

        $text = $speller->format($whole).' '.UiLang::get('po.words.dinars', null, $locale);
        if ($cents > 0) {
            $text .= ' '.UiLang::get('po.words.and', null, $locale).' '.$speller->format($cents).' '.UiLang::get('po.words.centimes', null, $locale);
        }

        return $text;
    }
}
