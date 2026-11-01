import { usePage } from '@inertiajs/vue3';
import { formatNumber, formatPercent } from '@/lib/numberFormat';

// Plain numbers and percents in the same locale as money (useFormatMoney), so
// every figure on a page shows the same digits and separators.
export function useFormatNumber() {
    const locale = usePage().props.locale || 'fr';

    return {
        formatNumber: (value) => formatNumber(value, locale),
        formatPercent: (value, options) => formatPercent(value, locale, options),
    };
}
