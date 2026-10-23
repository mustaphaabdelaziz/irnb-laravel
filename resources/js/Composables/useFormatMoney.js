import { usePage } from '@inertiajs/vue3';

// One formatter per locale: building an Intl.NumberFormat costs ~40 µs, and a
// long list used to build one for every amount it showed.
const formatters = new Map();

function formatterFor(locale) {
    if (!formatters.has(locale)) {
        formatters.set(locale, new Intl.NumberFormat(locale === 'ar' ? 'ar-DZ' : 'fr-DZ', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }));
    }

    return formatters.get(locale);
}

export function useFormatMoney() {
    const page = usePage();
    const locale = page.props.locale || 'fr';

    const formatMoney = (value) => {
        return formatterFor(locale).format(Number(value || 0));
    };

    return { formatMoney };
}
