<?php

namespace App\Services\Dashboard;

use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\Transaction;
use App\Services\Dashboard\Support\BranchScope;
use App\Services\Dashboard\Support\DeltaCalculator;
use App\Services\Dashboard\Support\MonthBucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The six numbers the dashboard leads with.
 *
 * Each tile carries its own value, its change against the previous period and
 * a twelve-month sparkline, so a reader never has to guess whether a number is
 * good. Two of them — debt and treasury — have no stored history and are
 * reconstructed; see the notes on those methods.
 */
class HeroStats
{
    public function get(DashboardFilters $filters): array
    {
        $months = MonthBucket::lastTwelve($filters->anchor());

        return [
            $this->members($filters),
            $this->collectionRate($filters),
            $this->netCashFlow($filters, $months),
            $this->outstandingDebt($filters, $months),
            $this->equipmentOnLoan($filters),
            $this->treasury($filters, $months),
        ];
    }

    private function members(DashboardFilters $filters): array
    {
        $active = (int) BranchScope::players(
            Player::query()->where('archived', false),
            $filters->branchId,
        )->count();

        // "New members" is the comparable quantity — the active headcount at a
        // past date is not stored, so joins in each window stand in for it.
        $delta = null;
        if ($filters->hasComparison()) {
            $current = (float) $this->joinsBetween($filters, $filters->from, $filters->to);
            $previous = (float) $this->joinsBetween($filters, $filters->prevFrom, $filters->prevTo);
            $delta = DeltaCalculator::compute($current, $previous);
        }

        return [
            'key' => 'members',
            'value' => $active,
            'format' => 'number',
            'delta' => $delta,
            'spark' => $this->monthlySeries(
                BranchScope::players(Player::query()->where('archived', false), $filters->branchId)
                    ->toBase(),
                'players.created_at',
                $filters,
                aggregate: 'count',
            ),
            'meta' => null,
        ];
    }

    private function joinsBetween(DashboardFilters $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        $query = BranchScope::players(Player::query()->where('archived', false), $filters->branchId);

        if ($from !== null) {
            $query->whereBetween('players.created_at', [$from, $to]);
        }

        return (int) $query->count();
    }

    /**
     * Paid over billed, exempt lines excluded from both sides.
     *
     * Null rather than zero when nothing is billed: "no subscriptions yet" and
     * "nobody has paid" are different facts and the tile says so.
     */
    private function collectionRate(DashboardFilters $filters): array
    {
        $rate = fn (?CarbonImmutable $from, ?CarbonImmutable $to): ?float => $this->rateBetween($filters, $from, $to);

        $value = $rate($filters->from, $filters->to);
        $delta = null;

        if ($filters->hasComparison() && $value !== null) {
            $previous = $rate($filters->prevFrom, $filters->prevTo);
            $delta = DeltaCalculator::compute($value, $previous);
        }

        return [
            'key' => 'collection_rate',
            'value' => $value,
            'format' => 'percent',
            'delta' => $delta,
            'spark' => $this->collectionSeries($filters),
            'meta' => null,
        ];
    }

    private function rateBetween(DashboardFilters $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): ?float
    {
        $row = $this->subscriptionLines($filters, $from, $to)
            ->selectRaw('SUM(amount_paid) as paid, SUM(amount_owed) as owed')
            ->first();

        $owed = (float) ($row->owed ?? 0);

        if ($owed <= 0.0) {
            return null;
        }

        return round((float) ($row->paid ?? 0) / $owed * 100, 1);
    }

    /**
     * Subscription lines whose effective due date falls in the window.
     *
     * A line's own due_date wins; lines without one fall back to 31 December of
     * their subscription year, which is the rule the spec fixes so that every
     * debt number on the dashboard buckets the same way.
     */
    private function subscriptionLines(DashboardFilters $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        $query = DB::table('player_subscriptions')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->where('players.archived', false)
            ->where('player_subscriptions.is_exempt', false);

        if ($branchIds = BranchScope::playerIdsQuery($filters->branchId)) {
            $query->whereIn('player_subscriptions.player_id', $branchIds);
        }

        if ($from !== null) {
            $query->whereBetween(DB::raw($this->effectiveDueDate()), [
                $from->toDateString(),
                $to->toDateString(),
            ]);
        }

        return $query;
    }

    private function effectiveDueDate(): string
    {
        return "COALESCE(player_subscriptions.due_date, (player_subscriptions.year || '-12-31'))";
    }

    /** @return list<float> */
    private function collectionSeries(DashboardFilters $filters): array
    {
        $labels = MonthBucket::lastTwelve($filters->anchor());
        $bucket = MonthBucket::expression($this->effectiveDueDate());

        $rows = $this->subscriptionLines($filters, null, null)
            ->selectRaw("{$bucket} as bucket, SUM(amount_paid) as paid, SUM(amount_owed) as owed")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return array_map(function (string $label) use ($rows): float {
            $row = $rows[$label] ?? null;
            $owed = (float) ($row->owed ?? 0);

            return $owed > 0 ? round((float) $row->paid / $owed * 100, 1) : 0.0;
        }, $labels);
    }

    private function netCashFlow(DashboardFilters $filters, array $months): array
    {
        $net = fn (?CarbonImmutable $from, ?CarbonImmutable $to): float => $this->sumTransactions($filters, 'income', $from, $to)
            - $this->sumTransactions($filters, 'expense', $from, $to);

        $value = $net($filters->from, $filters->to);
        $delta = $filters->hasComparison()
            ? DeltaCalculator::compute($value, $net($filters->prevFrom, $filters->prevTo))
            : null;

        $bucket = MonthBucket::expression('transaction_date');
        $rows = BranchScope::transactions(Transaction::query()->where('archived', false), $filters->branchId)
            ->selectRaw("{$bucket} as bucket, SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as net")
            ->groupBy('bucket')
            ->pluck('net', 'bucket')
            ->all();

        return [
            'key' => 'net_cash_flow',
            'value' => $value,
            'format' => 'money',
            'delta' => $delta,
            'spark' => MonthBucket::series($rows, $months),
            'meta' => null,
        ];
    }

    private function sumTransactions(DashboardFilters $filters, string $type, ?CarbonImmutable $from, ?CarbonImmutable $to): float
    {
        $query = BranchScope::transactions(
            Transaction::query()->where('archived', false)->where('transaction_type', $type),
            $filters->branchId,
        );

        if ($from !== null) {
            $query->whereBetween('transaction_date', [$from, $to]);
        }

        return (float) $query->sum('amount');
    }

    /**
     * Headline value is today's snapshot; the sparkline is an accrual
     * reconstruction, because no debt history is stored.
     *
     * The two will not always agree to the dinar, which is why the series is
     * labelled "accrued debt" in the UI rather than presented as the same
     * number over time.
     */
    private function outstandingDebt(DashboardFilters $filters, array $months): array
    {
        $value = (float) BranchScope::players(
            Player::query()->where('archived', false),
            $filters->branchId,
        )->sum('outstanding_debt');

        $bucket = MonthBucket::expression($this->effectiveDueDate());
        $accrued = $this->subscriptionLines($filters, null, null)
            ->selectRaw("{$bucket} as bucket, SUM(amount_owed - amount_paid) as unpaid")
            ->groupBy('bucket')
            ->pluck('unpaid', 'bucket')
            ->all();

        $spark = [];
        $running = 0.0;
        foreach ($months as $month) {
            $running += (float) ($accrued[$month] ?? 0);
            $spark[] = round($running, 2);
        }

        // The delta compares debt *accrued within* each window, not the running
        // total. A cumulative accrual can never fall, so comparing totals would
        // report "neutral or worse" forever and the tile would never once show
        // good news about debt being brought down.
        $delta = null;
        if ($filters->hasComparison()) {
            $current = $this->unpaidAccruedIn($filters, $filters->from, $filters->to);
            $previous = $this->unpaidAccruedIn($filters, $filters->prevFrom, $filters->prevTo);
            $delta = DeltaCalculator::compute($current, $previous, DeltaCalculator::DOWN_GOOD);
        }

        return [
            'key' => 'outstanding_debt',
            'value' => round($value, 2),
            'format' => 'money',
            'delta' => $delta,
            'spark' => $spark,
            'meta' => null,
        ];
    }

    /** Debt that came due in a window and is still unpaid. */
    private function unpaidAccruedIn(DashboardFilters $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): float
    {
        return (float) $this->subscriptionLines($filters, $from, $to)
            ->sum(DB::raw('amount_owed - amount_paid'));
    }

    private function equipmentOnLoan(DashboardFilters $filters): array
    {
        $open = EquipmentRental::query()->whereNull('return_date');

        if ($filters->branchId !== null) {
            $open->whereHas('equipmentItem.branches', fn ($q) => $q->whereKey($filters->branchId));
        }

        $rentals = $open->get(['id', 'due_date']);
        $today = CarbonImmutable::now()->startOfDay();

        $overdue = $rentals->filter(
            fn (EquipmentRental $rental): bool => $rental->due_date !== null
                && $rental->due_date->lt($today),
        )->count();

        return [
            'key' => 'equipment_on_loan',
            'value' => $rentals->count(),
            'format' => 'number',
            'delta' => null,
            'spark' => [],
            'meta' => ['overdue' => $overdue],
        ];
    }

    /**
     * Treasury today, with a reconstructed twelve-month line.
     *
     * Balances are snapshots too, so the series is opening funds plus the
     * running sum of non-archived transactions, the same derivation the finance
     * pages use.
     */
    private function treasury(DashboardFilters $filters, array $months): array
    {
        $accounts = DB::table('finance_accounts')->where('is_active', true);

        if ($filters->branchId !== null) {
            $accounts->where('branch_id', $filters->branchId);
        }

        $opening = (float) (clone $accounts)->sum('opening_balance');
        $value = (float) (clone $accounts)->sum('current_balance');

        $bucket = MonthBucket::expression('transaction_date');
        $monthly = BranchScope::transactions(Transaction::query()->where('archived', false), $filters->branchId)
            ->selectRaw("{$bucket} as bucket, SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as net")
            ->groupBy('bucket')
            ->pluck('net', 'bucket')
            ->all();

        $spark = [];
        $running = $opening;
        foreach ($months as $month) {
            $running += (float) ($monthly[$month] ?? 0);
            $spark[] = round($running, 2);
        }

        $delta = null;
        if ($filters->hasComparison() && count($spark) >= 2) {
            $delta = DeltaCalculator::compute(end($spark), $spark[count($spark) - 2]);
        }

        return [
            'key' => 'treasury',
            'value' => round($value, 2),
            'format' => 'money',
            'delta' => $delta,
            'spark' => $spark,
            'meta' => null,
        ];
    }

    /**
     * A dense twelve-month series from a base query.
     *
     * @return list<float>
     */
    private function monthlySeries(Builder $query, string $column, DashboardFilters $filters, string $aggregate): array
    {
        $labels = MonthBucket::lastTwelve($filters->anchor());
        $bucket = MonthBucket::expression($column);

        $rows = $query
            ->selectRaw("{$bucket} as bucket, {$aggregate}(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        return MonthBucket::series($rows, $labels);
    }
}
