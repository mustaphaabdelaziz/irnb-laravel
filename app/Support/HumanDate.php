<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Dates in words for printed documents, written the way the club writes them:
 * «28 مارس», «من 09 إلى 10 أكتوبر». Arabic uses the month names of Algeria.
 * The year is left out, because the document's title carries it, unless the
 * range crosses a year.
 */
final class HumanDate
{
    private const MONTHS = [
        'ar' => ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    public static function range(CarbonInterface $start, ?CarbonInterface $end, string $locale): string
    {
        $locale = isset(self::MONTHS[$locale]) ? $locale : 'en';
        if ($end === null || ! $end->greaterThan($start->copy()->endOfDay())) {
            return self::day($start, $locale, false);
        }

        $withYear = $start->year !== $end->year;
        $sameMonth = ! $withYear && $start->month === $end->month;
        $first = $sameMonth ? self::number($start, $locale) : self::day($start, $locale, $withYear);
        $last = self::day($end, $locale, $withYear);

        return match ($locale) {
            'ar' => "من {$first} إلى {$last}",
            'fr' => "du {$first} au {$last}",
            default => $sameMonth ? "{$first}–{$last}" : "{$first} – {$last}",
        };
    }

    private static function day(CarbonInterface $date, string $locale, bool $withYear): string
    {
        $text = self::number($date, $locale).' '.self::MONTHS[$locale][$date->month - 1];

        return $withYear ? $text.' '.$date->year : $text;
    }

    /** Two-digit day in Arabic and French (the club's style), plain in English. */
    private static function number(CarbonInterface $date, string $locale): string
    {
        return $locale === 'en' ? (string) $date->day : $date->format('d');
    }
}
