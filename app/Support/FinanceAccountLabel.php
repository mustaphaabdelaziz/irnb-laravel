<?php

namespace App\Support;

use App\Models\FinanceAccount;

/**
 * One name for a money account wherever the server writes it (export,
 * dashboard, statistics cards) — the PHP twin of
 * resources/js/Composables/useFinanceAccountLabel.js; keep both in step.
 *
 * Fonds Initial and coffres are named by the app, never by their stored
 * English name ("Opening Fund", "Football Treasury"). Caisses created
 * automatically before 2026-10 ("Senior Cash Register") show their
 * category's name; the seeded club-level "Cash" shows the club-cash label.
 * Reads the branch and category relations: eager-load them.
 */
final class FinanceAccountLabel
{
    /** Name the create_finance_tables migration gave the first club-level cash account. */
    private const SEEDED_CLUB_CASH = 'Cash';

    public static function for(?FinanceAccount $account): string
    {
        if (! $account) {
            return '';
        }

        if ($account->is_opening_fund) {
            return UiLang::get('opening_fund');
        }

        if ($account->is_treasury) {
            return strtr(UiLang::get('caisses.coffre_of'), ['{branch}' => self::branch($account)]);
        }

        $name = self::caisseName($account);

        return $account->branch ? $name.' — '.self::branch($account) : $name;
    }

    private static function branch(FinanceAccount $account): string
    {
        return $account->branch?->localized_name ?: UiLang::get('caisses.club_level');
    }

    private static function caisseName(FinanceAccount $account): string
    {
        $name = (string) $account->name;

        if ($account->category && str_ends_with($name, ' Cash Register')) {
            return $account->category->localized_name;
        }

        if (! $account->branch && $name === self::SEEDED_CLUB_CASH) {
            return UiLang::get('caisses.club_cash');
        }

        return $name;
    }
}
