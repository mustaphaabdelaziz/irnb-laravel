/**
 * Date helpers and kind colours shared by the attendance calendar views.
 * Dates travel as local 'Y-m-d' strings and months as 'Y-m' strings, never
 * through toISOString() (that is the UTC date and can be a day off).
 */
const pad = (n) => String(n).padStart(2, '0');

export const dateKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const monthKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
export const parseDay = (key) => new Date(`${key}T00:00:00`);

export function addDays(key, n) {
    const d = parseDay(key);
    d.setDate(d.getDate() + n);
    return dateKey(d);
}

export function addMonths(month, n) {
    const d = parseDay(`${month}-01`);
    d.setMonth(d.getMonth() + n);
    return monthKey(d);
}

/** Whole months from `from` to `to` ('Y-m'); 0 when equal. */
export function monthsBetween(from, to) {
    const [fy, fm] = from.split('-').map(Number);
    const [ty, tm] = to.split('-').map(Number);
    return (ty - fy) * 12 + (tm - fm);
}

export function toMinutes(hhmm) {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
}

/** Short day label ("Mon, 3 Feb") for a date key, in the given locale. */
export const dayLabel = (key, locale) => parseDay(key).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });

export const KIND_DOT = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };
export const KIND_BORDER = { regular: 'border-primary-500', preseason: 'border-amber-500', extra: 'border-violet-500' };
export const KIND_BLOCK = {
    regular: 'border-primary-500 bg-primary-50 text-primary-900 dark:bg-primary-500/15 dark:text-primary-100',
    preseason: 'border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-500/15 dark:text-amber-100',
    extra: 'border-violet-500 bg-violet-50 text-violet-900 dark:bg-violet-500/15 dark:text-violet-100',
};
