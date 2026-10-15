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
 *
 * A twin found by name takes the free seeded code (saved quietly), so a later
 * rename in the manager no longer sends its payments to "Other". The data
 * migration give_legacy_finance_categories_their_codes does the same up front.
 */
final class LegacyFinanceCategory
{
    /** Slug => code of the category seeded by create_finance_tables. */
    public const CODES = [
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
                ->where('name', self::twinName($slug))
                ->orderBy('id')->first();
            if ($twin) {
                // The code is free (the lookup above found nothing): claim it.
                if ($code && blank($twin->code)) {
                    $twin->code = $code;
                    $twin->saveQuietly();
                }

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

    /** The name the old observer gave a slug's category: "debt_payment" → "Debt Payment". */
    public static function twinName(string $slug): string
    {
        return Str::title(str_replace('_', ' ', $slug));
    }

    private static function byCode(string $type, string $code): ?FinanceCategory
    {
        return FinanceCategory::where('type', $type)->where('code', $code)->orderBy('id')->first();
    }
}
