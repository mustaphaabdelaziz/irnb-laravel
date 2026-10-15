<?php

namespace App\Services\Dashboard;

use App\Models\Transaction;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The four-figure strip that sits above a module's list.
 *
 * Deliberately small. A list page answers "which ones"; the strip answers
 * "how many, and how much" without the reader leaving for the dashboard.
 * Each method returns tiles in the same shape the dashboard's StatTile takes,
 * so the two surfaces stay visually identical.
 *
 * Strips are unfiltered totals for the module, not the page's current filter —
 * except Transactions: a ledger's first question is "how much in what I am
 * looking at", so its strip sums the list's own query. Members' debts stay a
 * club-wide figure there (and say so): a debt has no season, caisse or category.
 */
class ModuleStats
{
    /** @return list<array<string, mixed>> */
    public function players(): array
    {
        $row = DB::table('players')
            ->where('archived', false)
            ->selectRaw(
                // Active = not "Left the club", like the players list default;
                // the debt figures still count everyone who owes.
                'COUNT(CASE WHEN status_id IS NULL OR status_id NOT IN (SELECT id FROM player_statuses WHERE code = ?) THEN 1 END) as total, '
                .'COUNT(CASE WHEN outstanding_debt > 0 THEN 1 END) as with_debt, '
                .'SUM(outstanding_debt) as debt_total, '
                .'COUNT(CASE WHEN created_at >= ? THEN 1 END) as joined_this_month',
                ['left', CarbonImmutable::now()->startOfMonth()->toDateTimeString()],
            )
            ->first();

        return [
            $this->tile('players_active', (int) ($row->total ?? 0)),
            $this->tile('players_with_debt', (int) ($row->with_debt ?? 0)),
            $this->tile('players_debt_total', round((float) ($row->debt_total ?? 0), 2), 'money'),
            $this->tile('players_new', (int) ($row->joined_this_month ?? 0)),
            $this->tile('players_left', $this->playersLeftThisSeason()),
        ];
    }

    /**
     * Members who left during the current season, by their leave date —
     * archived or not, since a leaver who was later archived still left.
     */
    private function playersLeftThisSeason(): int
    {
        $season = Season::current();

        return DB::table('players')
            ->whereBetween('left_at', [$season->start()->toDateString(), $season->end()->toDateString()])
            ->count();
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(): array
    {
        $row = DB::table('player_subscriptions')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->where('players.archived', false)
            ->where('player_subscriptions.is_exempt', false)
            ->selectRaw(
                'COUNT(*) as enrolled, '
                .'SUM(amount_paid) as collected, '
                .'SUM(amount_owed - amount_paid) as owed'
            )
            ->first();

        $collected = (float) ($row->collected ?? 0);
        $owed = (float) ($row->owed ?? 0);
        $billed = $collected + $owed;

        return [
            $this->tile('subs_enrolled', (int) ($row->enrolled ?? 0)),
            $this->tile('subs_collected', round($collected, 2), 'money'),
            $this->tile('subs_owed', round($owed, 2), 'money'),
            // Null rather than zero when nothing has been billed: "nobody owes
            // anything yet" is not a 0% collection rate.
            $this->tile('subs_rate', $billed > 0 ? round($collected / $billed * 100, 1) : null, 'percent'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function equipment(): array
    {
        $outstanding = DB::table('equipment_rentals')
            ->whereNull('return_date')
            ->selectRaw('equipment_item_id, COALESCE(SUM(quantity - returned_quantity), 0) as out')
            ->groupBy('equipment_item_id');

        $row = DB::table('equipment_items')
            ->leftJoinSub($outstanding, 'r', 'r.equipment_item_id', '=', 'equipment_items.id')
            ->whereNotIn('equipment_items.status', ['Retired', 'Out of Service', 'Lost'])
            ->selectRaw(
                'COUNT(DISTINCT equipment_items.catalog_id) as catalogs, '
                .'SUM(equipment_items.quantity) as units, '
                .'SUM(equipment_items.quantity * COALESCE(equipment_items.unit_price, 0)) as value, '
                .'SUM(COALESCE(r.out, 0)) as on_loan'
            )
            ->first();

        return [
            $this->tile('equip_catalogs', (int) ($row->catalogs ?? 0)),
            $this->tile('equip_units', (int) ($row->units ?? 0)),
            $this->tile('equip_value', round((float) ($row->value ?? 0), 2), 'money'),
            $this->tile('equip_on_loan', (int) ($row->on_loan ?? 0)),
        ];
    }

    /**
     * @param  Builder<Transaction>  $filtered  the list query, filters applied
     * @return list<array<string, mixed>>
     */
    public function transactions(Builder $filtered): array
    {
        $row = (clone $filtered)->toBase()
            ->reorder()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income, "
                ."COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense"
            )
            ->first();

        $income = round((float) ($row->income ?? 0), 2);
        $expense = round((float) ($row->expense ?? 0), 2);
        $debts = (float) DB::table('players')->where('archived', false)->sum('outstanding_debt');

        return [
            $this->tile('tx_income', $income, 'money'),
            $this->tile('tx_expense', $expense, 'money'),
            $this->tile('tx_net', round($income - $expense, 2), 'money'),
            $this->tile('tx_debts', round($debts, 2), 'money'),
        ];
    }

    /** @return array<string, mixed> */
    private function tile(string $key, float|int|null $value, string $format = 'number'): array
    {
        return ['key' => $key, 'value' => $value, 'format' => $format];
    }
}
