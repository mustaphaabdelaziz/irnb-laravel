import { usePage } from '@inertiajs/vue3';

export function useFormatMoney() {
    const page = usePage();
    const locale = page.props.locale || 'fr';

    const formatMoney = (value) => {
        return new Intl.NumberFormat(locale === 'ar' ? 'ar-DZ' : 'fr-DZ', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(value || 0));
    };

    return { formatMoney };
}
