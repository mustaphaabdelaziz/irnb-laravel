<?php

namespace App\Support;

use App\Models\Player;
use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * The label a transaction is known by on screens, receipts and exports.
 *
 * A title typed by the user wins. Transactions the app records by itself
 * (subscription payments, donations, debt payments, equipment purchases,
 * imports) carry none, so their label is built here at render time — in the
 * viewer's language — rather than stored, which would freeze it in whatever
 * language the recorder happened to use.
 *
 * Reads only relations the caller eager-loaded (RELATIONS); it never
 * lazy-loads, so labelling a page of rows costs no extra queries.
 */
final class TransactionTitle
{
    /** Eager loads that for() and decorate() read. */
    public const RELATIONS = [
        'financeCategory',
        'relatedPlayer:id,firstname,lastname,membership_id',
        'playerSubscription:id,subscription_id,label,year',
        'playerSubscription.subscription:id,name,year',
    ];

    public static function for(Transaction $transaction): string
    {
        if (filled($transaction->title)) {
            return (string) $transaction->title;
        }

        $parts = [
            self::categoryLabel($transaction),
            self::subscriptionLabel($transaction),
            self::player($transaction)?->short_name,
        ];

        return implode(' · ', array_filter($parts, fn (?string $part) => filled($part)));
    }

    /**
     * Adds display_title and player_summary for the UI, then drops the helper
     * relations so they are not serialized: PlayerSubscription appends
     * accessors that would lazy-load once per row.
     *
     * The returned model carries two non-column attributes (display_title,
     * player_summary) and must not be saved.
     */
    public static function decorate(Transaction $transaction): Transaction
    {
        $player = self::player($transaction);

        $transaction->setAttribute('display_title', self::for($transaction));
        $transaction->setAttribute('player_summary', $player ? [
            'id' => $player->id,
            'name' => $player->short_name,
            'membership_id' => $player->membership_id,
        ] : null);

        return $transaction->unsetRelation('relatedPlayer')->unsetRelation('playerSubscription');
    }

    /** The linked player, only when the transaction really points at one. */
    private static function player(Transaction $transaction): ?Player
    {
        if ($transaction->related_entity_type !== 'Player' || ! $transaction->relationLoaded('relatedPlayer')) {
            return null;
        }

        return $transaction->relatedPlayer;
    }

    private static function categoryLabel(Transaction $transaction): string
    {
        if ($transaction->relationLoaded('financeCategory') && $transaction->financeCategory) {
            return $transaction->financeCategory->localized_name;
        }

        $slug = (string) ($transaction->category ?: 'transaction');

        return UiLang::get($slug, Str::headline($slug));
    }

    private static function subscriptionLabel(Transaction $transaction): ?string
    {
        if (! $transaction->relationLoaded('playerSubscription') || ! $transaction->playerSubscription) {
            return null;
        }

        $line = $transaction->playerSubscription;
        $subscriptionName = $line->relationLoaded('subscription') ? $line->subscription?->name : null;
        $name = trim((string) ($line->label ?: $subscriptionName));
        $year = $line->year ? (string) $line->year : '';

        // "Dette 2024" already names its year; don't print it twice.
        if ($year === '' || str_contains($name, $year)) {
            return $name !== '' ? $name : null;
        }

        return trim("{$name} {$year}");
    }
}
