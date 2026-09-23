/**
 * The folder number as it is written on paper: four digits, zero-padded.
 * Kept in one place so the list, the player page and the print screens cannot
 * drift apart.
 */
export function formatFileNumber(number) {
    if (number === null || number === undefined || number === '') return '—';
    return String(number).padStart(4, '0');
}

/** Which drawer holds it. `size` comes from the club setting. */
export function fileDrawer(number, size = 100) {
    if (!number) return null;
    return Math.ceil(Number(number) / (Number(size) || 100));
}
