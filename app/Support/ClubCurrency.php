<?php

namespace App\Support;

/**
 * The club's currency, as chosen in Settings, and how amounts are labelled.
 *
 * One rule, mirrored by resources/js/lib/currencyLabel.js for the screens:
 * a custom symbol typed in Settings wins; otherwise the standard sign of the
 * currency — the dinar in the reader's language (the UI's `currency_symbol`
 * key: د.ج / DA / DZD), € for the euro, $ for the dollar — else the code.
 */
final class ClubCurrency
{
    public const DEFAULT = 'DZD';

    /** Standard signs that read the same in every language. */
    private const SIGNS = ['EUR' => '€', 'USD' => '$'];

    public static function code(): string
    {
        $code = strtoupper(trim((string) ClubSettings::get('currency', '')));

        return $code !== '' ? $code : self::DEFAULT;
    }

    /** The custom symbol from Settings, or null when none is set (or it only repeats the code). */
    public static function symbol(): ?string
    {
        $symbol = trim((string) ClubSettings::get('currencySymbol', ''));

        return $symbol !== '' && strtoupper($symbol) !== self::code() ? $symbol : null;
    }

    /** What follows an amount, in $locale (default: the app locale). */
    public static function label(?string $locale = null): string
    {
        if (($symbol = self::symbol()) !== null) {
            return $symbol;
        }

        $code = self::code();

        return match (true) {
            $code === 'DZD' => UiLang::get('currency_symbol', 'DZD', $locale),
            isset(self::SIGNS[$code]) => self::SIGNS[$code],
            default => $code,
        };
    }
}
