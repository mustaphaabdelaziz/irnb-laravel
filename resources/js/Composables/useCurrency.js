import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { currencyLabel as labelFor } from '@/lib/currencyLabel';

/**
 * The label shown after amounts: the club currency from Settings (shared by
 * HandleInertiaRequests as `currency`), never just the screen language. The
 * `currency_symbol` key is only the dinar's name in each language.
 */
export function useCurrency() {
    const page = usePage();
    const { t } = useI18n();

    const currencyLabel = computed(() => labelFor(page.props.currency, t('currency_symbol')));

    return { currencyLabel };
}
