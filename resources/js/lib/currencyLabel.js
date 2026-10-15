/**
 * What follows an amount on screen. Same rule as App\Support\ClubCurrency:
 * a custom symbol from Settings wins; otherwise the currency's standard sign —
 * the dinar in the screen's language (`dinarLabel`, the `currency_symbol` key),
 * € for the euro, $ for the dollar — else the code itself.
 *
 * Pure (no Vue) so tests/js can check it.
 */
const SIGNS = { EUR: '€', USD: '$' };

export function currencyLabel(currency, dinarLabel) {
    const code = String(currency?.code ?? '').trim().toUpperCase() || 'DZD';
    const symbol = String(currency?.symbol ?? '').trim();

    if (symbol !== '' && symbol.toUpperCase() !== code) return symbol;
    if (code === 'DZD') return dinarLabel;

    return SIGNS[code] ?? code;
}
