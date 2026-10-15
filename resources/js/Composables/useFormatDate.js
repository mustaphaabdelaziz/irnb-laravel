import { usePage } from '@inertiajs/vue3';

const INTL_LOCALE = { ar: 'ar-DZ', fr: 'fr-FR', en: 'en-GB' };

/**
 * Dates as the app's language writes them ("1 oct. 2026", "1 أكتوبر 2026").
 * The server sends dates as "Y-m-d" and timestamps as "Y-m-d H:i"; both are
 * read as local time, so a date never slips a day across a timezone.
 */
export function useFormatDate() {
    const page = usePage();
    const locale = INTL_LOCALE[page.props.locale] ?? 'fr-FR';

    const parse = (value) => {
        if (!value) return null;
        const text = String(value);
        const date = /^\d{4}-\d{2}-\d{2}$/.test(text) ? new Date(`${text}T00:00:00`) : new Date(text.replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    };

    const formatDate = (value) => {
        const date = parse(value);
        return date ? date.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
    };

    const formatDateTime = (value) => {
        const date = parse(value);
        return date ? date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    };

    return { formatDate, formatDateTime };
}
