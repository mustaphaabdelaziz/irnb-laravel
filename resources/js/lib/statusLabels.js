/**
 * Every stored status code the screens render, per domain, mapped to its i18n
 * key. The database keeps stable codes; only the label is translated.
 *
 * Codes match case-insensitively: transactions store "Paid", subscription
 * lines expose "paid" — both are the same code. Pure data + functions (no Vue)
 * so scripts/i18n-check.mjs can verify every key resolves in ar/fr/en.
 */
export const STATUS_KEYS = {
    payment: { paid: 'paid', partial: 'partial', unpaid: 'unpaid', exempt: 'exempt' },
    payment_method: { cash: 'cash', bank: 'bank_transfer', ccp: 'ccp', baridimob: 'baridimob', other: 'other' },
    equipment: {
        available: 'available',
        rented: 'rented',
        'under repair': 'under_repair',
        'out of service': 'out_of_service',
        lost: 'lost',
        retired: 'retired',
    },
    meeting: { scheduled: 'scheduled', held: 'held', cancelled: 'cancelled' },
    rental_type: { rental: 'equipment.rental', assignment: 'equipment.assignment' },
};

const isEmpty = (code) => code === null || code === undefined || code === '';

/** The i18n key for a stored code, or null when the code is unknown. */
export function statusKey(domain, code) {
    if (isEmpty(code)) return null;
    return STATUS_KEYS[domain]?.[String(code).toLowerCase()] ?? null;
}

/** Translated label; '—' when empty, the raw code when unmapped (never blank). */
export function statusLabel(t, domain, code) {
    if (isEmpty(code)) return '—';
    const key = statusKey(domain, code);
    return key ? t(key) : String(code);
}
