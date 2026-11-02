// Plain numbers and percents in the app language, the same way money is: Arabic
// gets ar-DZ, everything else fr-DZ. Kept free of Vue so it is unit-testable;
// useFormatNumber() supplies the page locale.

export function numberLocale(appLocale) {
    return appLocale === 'ar' ? 'ar-DZ' : 'fr-DZ';
}

// One formatter per (locale, options): building an Intl.NumberFormat is the
// expensive part and a table formats hundreds of cells.
const formatters = new Map();

function formatterFor(appLocale, minimumFractionDigits, maximumFractionDigits) {
    const locale = numberLocale(appLocale);
    const key = `${locale}|${minimumFractionDigits}|${maximumFractionDigits}`;
    if (!formatters.has(key)) {
        formatters.set(key, new Intl.NumberFormat(locale, { minimumFractionDigits, maximumFractionDigits }));
    }

    return formatters.get(key);
}

// Text that is not a number is shown as-is; a non-finite number is a dash —
// never "NaN" on screen. Returns null when the value is a usable number.
function fallback(value) {
    const number = Number(value);
    if (Number.isFinite(number)) return null;
    if (typeof value === 'string' && Number.isNaN(number)) return value;

    return '—';
}

// Blank (null/'') is the caller's job: it comes back as "0", not a dash.
export function formatNumber(value, appLocale) {
    const odd = fallback(value);
    if (odd !== null) return odd;

    return formatterFor(appLocale, 0, 2).format(Number(value));
}

// How many characters a figure takes, for shrinking it to fit its tile on one
// line. Null when the text has words: those keep wrapping normally.
export function figureChars(value) {
    const text = String(value ?? '').trim();
    if (!/\p{Nd}/u.test(text) || !/^[\p{Nd}\p{Zs}.,\u066B\u066C%\u066A+\-\u2212/']+$/u.test(text)) return null;

    return [...text].length;
}

export function formatPercent(value, appLocale, { trim = false } = {}) {
    const odd = fallback(value);
    if (odd !== null) return odd;

    return `${formatterFor(appLocale, trim ? 0 : 1, 1).format(Number(value))}%`;
}
