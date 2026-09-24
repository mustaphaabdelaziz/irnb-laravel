<?php

namespace App\Services\Dashboard;

use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\PlayerSubscription;
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
        // Both windows come back from one query rather than two.
        $delta = null;
        if ($filters->hasComparison()) {
            $joins = $this->twoWindowAggregate(
                BranchScope::players(Player::query()->where('archived', false), $filters->branchId)->toBase(),
                'players.created_at',
                'COUNT(players.id)',
                $filters,
            );
            $delta = DeltaCalculator::compute($joins['current'], $joins['previous']);
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

    /**
     * One query, both windows.
     *
     * A tile needs its current value and the previous period's to show a
     * delta. Asking twice doubles the hero row's query count for no gain, so
     * the two windows ride in as conditional aggregates on a single pass.
     *
     * @param  string  $aggregate  a SQL aggregate over the column of interest, e.g. 'SUM(amount)'
     * @return array{current: float, previous: float}
     */
    private function twoWindowAggregate(Builder $query, string $dateColumn, string $aggregate, DashboardFilters $filters): array
    {
        $inner = preg_replace('/^(\w+)\((.*)\)$/', '$2', $aggregate) ?? '*';
        $function = preg_replace('/^(\w+)\(.*\)$/', '$1', $aggregate) ?? 'SUM';
        $zero = $function === 'COUNT' ? 'NULL' : '0';

        $row = $query->selectRaw(
            "{$function}(CASE WHEN {$dateColumn} BETWEEN ? AND ? THEN {$inner} ELSE {$zero} END) as current_window, "
            ."{$function}(CASE WHEN {$dateColumn} BETWEEN ? AND ? THEN {$inner} ELSE {$zero} END) as previous_window",
            [
                $filters->from, $filters->to,
                $filters->prevFrom, $filters->prevTo,
            ],
        )->first();

        return [
            'current' => (float) ($row->current_window ?? 0),
            'previous' => (float) ($row->previous_window ?? 0),
        ];
    }

    /**
     * Paid over billed, exempt lines excluded from both sides.
     *
     * Null rather than zero when nothing is billed: "no subscriptions yet" and
     * "nobody has paid" are different facts and the tile says so.
     */
    private function collectionRate(DashboardFilters $filters): array
    {
        [$value, $previous] = $this->rateWindows($filters);

        $delta = ($filters->hasComparison() && $value !== null)
            ? DeltaCalculator::compute($value, $previous)
            : null;

        return [
            'key' => 'collection_rate',
            'value' => $value,
            'format' => 'percent',
            'delta' => $delta,
            'spark' => $this->collectionSeries($filters),
            'meta' => null,
        ];
    }

    /**
     * Collection rate for the current and previous windows, in one query.
     *
     * Null rather than zero when nothing is billed in a window — "nothing was
     * due" and "nothing was collected" are different facts.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function rateWindows(DashboardFilters $filters): array
    {
        $due = $this->effectiveDueDate();
        $query = $this->subscriptionLines($filters, null, null);

        if ($filters->isAllTime()) {
            $row = $query->selectRaw('SUM(amount_paid) as current_paid, SUM(amount_owed) as current_owed')->first();

            return [$this->ratio($row->current_paid ?? 0, $row->current_owed ?? 0), null];
        }

        $row = $query->selectRaw(
            "SUM(CASE WHEN {$due} BETWEEN ? AND ? THEN amount_paid ELSE 0 END) as current_paid, "
            ."SUM(CASE WHEN {$due} BETWEEN ? AND ? THEN amount_owed ELSE 0 END) as current_owed, "
            ."SUM(CASE WHEN {$due} BETWEEN ? AND ? THEN amount_paid ELSE 0 END) as previous_paid, "
            ."SUM(CASE WHEN {$due} BETWEEN ? AND ? THEN amount_owed ELSE 0 END) as previous_owed",
            [
                $filters->from->toDateString(), $filters->to->toDateString(),
                $filters->from->toDateString(), $filters->to->toDateString(),
                $filters->prevFrom->toDateString(), $filters->prevTo->toDateString(),
                $filters->prevFrom->toDateString(), $filters->prevTo->toDateString(),
            ],
        )->first();

        return [
            $this->ratio($row->current_paid ?? 0, $row->current_owed ?? 0),
            $this->ratio($row->previous_paid ?? 0, $row->previous_owed ?? 0),
        ];
    }

    private function ratio(float|int|string|null $paid, float|int|string|null $owed): ?float
    {
        $owed = (float) $owed;

        return $owed > 0.0 ? round((float) $paid / $owed * 100, 1) : null;
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
        $signed = "CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END";

        $base = fn () => BranchScope::transactions(
            Transaction::query()->where('archived', false),
            $filters->branchId,
        );

        if ($filters->isAllTime()) {
            $value = (float) $base()->sum(DB::raw($signed));
            $delta = null;
        } else {
            $windows = $this->twoWindowAggregate(
                $base()->toBase(),
                'transaction_date',
                "SUM({$signed})",
                $filters,
            );
            $value = round($windows['current'], 2);
            $delta = $filters->hasComparison()
                ? DeltaCalculator::compute($value, $windows['previous'])
                : null;
        }

        $bucket = MonthBucket::expression('transaction_date');
        $rows = $base()
            ->selectRaw("{$bucket} as bucket, SUM({$signed}) as net")
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
        $accrued = PlayerSubscription::whereCountsAsDebt($this->subscriptionLines($filters, null, null))
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
            $due = $this->effectiveDueDate();
            $windows = PlayerSubscription::whereCountsAsDebt($this->subscriptionLines($filters, null, null))->selectRaw(
                "SUM(CASE WHEN {$due} BETWEEN ? AND ? THEN amount_owed - amount_paid ELSE 0 END) as current_window, "
                ."SUM(CASE WHEN {$due} BETWEEN ? AND ? THEN amount_owed - amount_paid ELSE 0 END) as previous_window",
                [
                    $filters->from->toDateString(), $filters->to->toDateString(),
                    $filters->prevFrom->toDateString(), $filters->prevTo->toDateString(),
                ],
            )->first();

            $delta = DeltaCalculator::compute(
                (float) ($windows->current_window ?? 0),
                (float) ($windows->previous_window ?? 0),
                DeltaCalculator::DOWN_GOOD,
            );
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

        // Current balances are summed over ROOT accounts only. A treasury's
        // current_balance is a roll-up that already contains every child
        // register's money (see FinanceService::recomputeAccountBalances), so
        // summing every row counts the branch's cash twice. Opening balances
        // are each account's own and are summed across all of them.
        $totals = $accounts
            ->selectRaw(
                'SUM(opening_balance) as opening, '
                .'SUM(CASE WHEN parent_account_id IS NULL THEN current_balance ELSE 0 END) as current'
            )
            ->first();

        $opening = (float) ($totals->opening ?? 0);
        $value = (float) ($totals->current ?? 0);

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
