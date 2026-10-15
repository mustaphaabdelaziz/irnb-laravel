<?php

namespace App\Support;

use App\Models\FinanceCategory;
use Illuminate\Support\Str;

/**
 * The finance category for a transaction that arrives with only a legacy slug
 * in `category` — player payments, equipment purchases, old imports.
 *
 * Never creates a category from a slug: the slug of an Arabic name is a
 * transliteration ("msaryf_ashghal_almkr"), and title-casing "subscription"
 * planted a "Subscription" next to the seeded "Subscriptions".
 *
 * Order: the seeded category for a known slug (matched by code, so a club
 * rename is harmless) → a category already named like the slug (a club that
 * deleted the seeded one but kept the legacy twin) → the single "Other" of
 * that type, recreated once if it was deleted.
 */
final class LegacyFinanceCategory
{
    /** Slug => code of the category seeded by create_finance_tables. */
    private const CODES = [
        'income' => [
            'subscription' => 'SUB', 'subscriptions' => 'SUB',
            'membership_fee' => 'MEM', 'membership_fees' => 'MEM',
            'donation' => 'DON', 'donations' => 'DON',
            'grant' => 'GRT', 'grants' => 'GRT', 'subsidy' => 'GRT',
            'debt_payment' => 'ARR', 'debts' => 'ARR', 'arrears' => 'ARR',
            'event' => 'EVR', 'events' => 'EVR',
        ],
        'expense' => [
            'equipment' => 'EQP', 'equipment_purchase' => 'EQP',
            'maintenance' => 'MNT', 'repairs' => 'MNT',
            'supplies' => 'SUP',
            'works' => 'WRK', 'construction' => 'WRK',
            'utilities' => 'UTL',
            'salaries' => 'SAL', 'wages' => 'SAL',
            'event' => 'EVE', 'events' => 'EVE',
            'administrative' => 'ADM',
        ],
    ];

    /** Type => [code, base name] of the catch-all category. */
    private const OTHER = [
        'income' => ['OIN', 'Other Income'],
        'expense' => ['OEX', 'Other Expense'],
    ];

    public static function resolve(string $type, ?string $slug): FinanceCategory
    {
        $type = $type === 'expense' ? 'expense' : 'income';
        $slug = str_replace(['-', ' '], '_', mb_strtolower(trim((string) $slug)));

        $code = self::CODES[$type][$slug] ?? null;
        if ($code && $seeded = self::byCode($type, $code)) {
            return $seeded;
        }

        if ($slug !== '') {
            $twin = FinanceCategory::where('type', $type)
                ->where('name', Str::title(str_replace('_', ' ', $slug)))
                ->orderBy('id')->first();
            if ($twin) {
                return $twin;
            }
        }

        [$otherCode, $otherName] = self::OTHER[$type];

        return self::byCode($type, $otherCode)
            ?? FinanceCategory::firstOrCreate(
                ['type' => $type, 'name' => $otherName],
                ['code' => $otherCode, 'is_active' => true, 'is_system' => true],
            );
    }

    private static function byCode(string $type, string $code): ?FinanceCategory
    {
        return FinanceCategory::where('type', $type)->where('code', $code)->orderBy('id')->first();
    }
}
