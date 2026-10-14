import { useI18n } from 'vue-i18n';

/**
 * One label for a money account on every page: "Football safe" for a coffre,
 * "Caisse seniors — Football" for a caisse, the name alone at club level.
 * Caisses created automatically before 2026-10 carry an English name
 * ("Senior Cash Register"); those show their category's localized name.
 */
export function useFinanceAccountLabel() {
    const { t } = useI18n();

    const branchLabel = (branch) => branch?.localized_name || branch?.name || t('caisses.club_level');

    const caisseName = (account) => {
        const category = account.category;
        if (category && account.name === `${category.name} Cash Register`) {
            return category.localized_name || category.name;
        }
        return account.name;
    };

    const accountLabel = (account) => {
        if (!account) return '-';
        if (account.is_opening_fund) return t('opening_fund');
        if (account.is_treasury) return t('caisses.coffre_of', { branch: branchLabel(account.branch) });
        return account.branch ? `${caisseName(account)} — ${branchLabel(account.branch)}` : caisseName(account);
    };

    return { accountLabel, branchLabel, caisseName };
}
