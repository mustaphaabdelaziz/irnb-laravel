<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\Transaction;
use App\Support\FinanceAccountLabel;

/**
 * What an edit changed on a transaction. Take a snapshot before the save and
 * one after, then compare: values the observer sets on save (a default
 * register, today's date for an undated entry) are then the real ones.
 *
 * Season fields are not tracked: they follow the date.
 */
final class TransactionChanges
{
    /** Tracked fields, in the order the history lists them, with how each is compared. */
    public const FIELDS = [
        'title' => 'text',
        'amount' => 'money',
        'transaction_date' => 'date',
        'transaction_type' => 'text',
        'finance_category_id' => 'id',
        'finance_account_id' => 'id',
        // The linked player: "player X" or "no player".
        'related_entity_id' => 'id',
        'payment_method' => 'text',
        'payment_account' => 'text',
        'payment_ccp_key' => 'text',
        'payment_bank_name' => 'text',
        'payment_holder' => 'text',
        'payment_reference' => 'text',
        'status' => 'text',
        'description' => 'text',
    ];

    /** @return array<string, mixed> */
    public static function snapshot(Transaction $transaction): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $kind) {
            $raw = $transaction->getAttribute($field);
            // A link counts only when it points at a player.
            if ($field === 'related_entity_id' && $transaction->related_entity_type !== 'Player') {
                $raw = null;
            }
            $empty = $raw === null || $raw === '';

            $values[$field] = match ($kind) {
                'money' => round((float) $raw, 2),
                // By day: the column keeps no meaningful hour.
                'date' => $raw?->format('Y-m-d'),
                'id' => $empty ? null : (int) $raw,
                // "" and null are the same empty field.
                default => $empty ? null : (string) $raw,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * Names the category, register and player on both sides, as they are
     * called now: the history can still say which one it was after it is deleted.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @return array<string, array<string, mixed>>
     */
    public static function withLabels(array $changes): array
    {
        if (isset($changes['finance_category_id'])) {
            $names = FinanceCategory::whereIn('id', self::ids($changes['finance_category_id']))
                ->get()
                ->mapWithKeys(fn (FinanceCategory $category) => [$category->id => $category->localized_name]);

            $changes['finance_category_id'] = self::labelled($changes['finance_category_id'], $names->all());
        }

        if (isset($changes['finance_account_id'])) {
            $names = FinanceAccount::with(['branch', 'category'])
                ->whereIn('id', self::ids($changes['finance_account_id']))
                ->get()
                ->mapWithKeys(fn (FinanceAccount $account) => [$account->id => FinanceAccountLabel::for($account)]);

            $changes['finance_account_id'] = self::labelled($changes['finance_account_id'], $names->all());
        }

        if (isset($changes['related_entity_id'])) {
            $names = Player::whereIn('id', self::ids($changes['related_entity_id']))
                ->get(['id', ...Player::NAME_COLUMNS])
                ->mapWithKeys(fn (Player $player) => [$player->id => $player->fullname]);

            $changes['related_entity_id'] = self::labelled($changes['related_entity_id'], $names->all());
        }

        return $changes;
    }

    /** @return list<int> */
    private static function ids(array $change): array
    {
        return array_values(array_filter([$change['old'] ?? null, $change['new'] ?? null]));
    }

    /** @param array<int, string> $names */
    private static function labelled(array $change, array $names): array
    {
        return [
            'old' => $change['old'],
            'new' => $change['new'],
            'old_label' => $change['old'] === null ? null : ($names[$change['old']] ?? null),
            'new_label' => $change['new'] === null ? null : ($names[$change['new']] ?? null),
        ];
    }
}
