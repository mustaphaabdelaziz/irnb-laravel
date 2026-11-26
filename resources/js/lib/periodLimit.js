/**
 * True when a 'YYYY-MM-DD' range is longer than `months` months: `to` may be at
 * most from + months − 1 day (the rule of the period sheet's controller).
 */
export function periodTooLong(from, to, months = 13) {
    if (!from || !to) return false;
    const [y, m, d] = from.split('-').map(Number);
    const target = new Date(y, m - 1 + months, 1);
    const day = Math.min(d, new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate());
    const limit = new Date(target.getFullYear(), target.getMonth(), day - 1);
    const key = `${limit.getFullYear()}-${String(limit.getMonth() + 1).padStart(2, '0')}-${String(limit.getDate()).padStart(2, '0')}`;
    return to > key;
}
