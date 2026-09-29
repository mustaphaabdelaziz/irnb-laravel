/**
 * Algerian phone numbers, written the way people read them out:
 *   mobile   05 59 45 39 48   (05/06/07 + 8 digits, in pairs)
 *   landline 021 23 45 67     (0 + 2-digit area code, then pairs)
 *   intl     +213 5 59 45 39 48
 *
 * Numbers are stored as bare digits (a leading + kept) and only grouped for
 * display, so search and imports never have to cope with spacing.
 */

/** Digits only, keeping a leading "+" (or 00) for an international number. */
export function phoneDigits(value) {
    const raw = String(value ?? '').trim();
    const digits = raw.replace(/\D/g, '');
    if (raw.startsWith('+')) return `+${digits}`;
    // 00 is the dialled form of "+" (00213 … = +213 …).
    return digits.startsWith('00') ? `+${digits.slice(2)}` : digits;
}

const pairs = (digits) => digits.match(/.{1,2}/g)?.join(' ') ?? '';

/** The grouped form; works on a partial number too, so it can run as the user types. */
export function formatPhone(value) {
    const clean = phoneDigits(value);
    if (!clean) return '';

    // +213 5 59 45 39 48 (the national 0 is dropped after the country code).
    const intl = clean.match(/^\+?213(\d*)$/);
    if (intl && (clean.startsWith('+') || clean.length > 10)) {
        const rest = intl[1];
        if (!rest) return '+213';
        return `+213 ${rest.slice(0, 1)} ${pairs(rest.slice(1))}`.trim();
    }

    // Some other country: leave it as typed, digits only.
    if (clean.startsWith('+')) return clean;

    // Landline: 0 + two-digit area code (021, 031 …), then pairs.
    if (/^0[2-49]/.test(clean)) {
        return `${clean.slice(0, 3)} ${pairs(clean.slice(3))}`.trim();
    }

    // Mobile (05/06/07) and anything else: pairs.
    return pairs(clean);
}
