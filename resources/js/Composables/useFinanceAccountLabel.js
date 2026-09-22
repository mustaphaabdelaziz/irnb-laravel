import { useI18n } from 'vue-i18n';

/**
 * One display label for a cash register on every page: "Branch — Treasury",
 * "Branch — Category", or the account's own name (with its branch, if any).
 * Expects the account's branch/category loaded with their localized_name.
 */
export function useFinanceAccountLabel() {
    const { t } = useI18n();

    const branchLabel = (branch) => branch?.localized_name || branch?.name || t('club_wide');

    const accountLabel = (account) => {
        if (!account) return '-';
        if (account.is_treasury) return `${branchLabel(account.branch)} — ${t('treasury')}`;
        const category = account.category?.localized_name || account.category?.name;
        if (category) return `${branchLabel(account.branch)} — ${category}`;
        return account.branch ? `${account.name} — ${branchLabel(account.branch)}` : account.name;
    };

    return { accountLabel, branchLabel };
}
