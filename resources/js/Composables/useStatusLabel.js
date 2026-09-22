import { useI18n } from 'vue-i18n';
import { statusLabel } from '@/lib/statusLabels';

/** `statusLabel('payment', tx.status)` → the label in the current language. */
export function useStatusLabel() {
    const { t } = useI18n();

    return { statusLabel: (domain, code) => statusLabel(t, domain, code) };
}
