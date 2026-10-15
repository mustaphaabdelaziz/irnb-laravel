import { useI18n } from 'vue-i18n';

/**
 * Caisses created automatically before 2026-10 carry an English name
 * ("Senior Cash Register"). Any linked caisse still named that way shows its
 * category's localized name — even after the category was renamed.
 */
export function isLegacyAutoName(account) {
    return !!(account?.category && typeof account.name === 'string' && account.name.endsWith(' Cash Register'));
}

/**
 * One label for a money account on every page: "Football safe" for a coffre,
 * "Caisse seniors — Football" for a caisse, the name alone at club level.
 * Keep in step with app/Support/FinanceAccountLabel.php.
 */
export function useFinanceAccountLabel() {
    const { t } = useI18n();

    const branchLabel = (branch) => branch?.localized_name || branch?.name || t('caisses.club_level');

    const caisseName = (account) => {
        if (isLegacyAutoName(account)) {
            return account.category.localized_name || account.category.name;
        }
        // The club-level cash account the app seeded, under its English seed name.
        if (!account.branch && !account.is_treasury && account.name === 'Cash') {
            return t('caisses.club_cash');
        }
        return account.name;
    };

    const accountLabel = (account) => {
        if (!account) return '-';
        if (account.is_opening_fund) return t('opening_fund');
        if (account.is_treasury) return t('caisses.coffre_of', { branch: branchLabel(account.branch) });
        return account.branch ? `${caisseName(account)} — ${branchLabel(account.branch)}` : caisseName(account);
    };

    return { accountLabel, branchLabel, caisseName, isLegacyAutoName };
}
