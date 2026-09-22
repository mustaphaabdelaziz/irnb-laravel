<?php

namespace App\Services\Dashboard;

use App\Models\EquipmentRental;
use App\Models\Transaction;
use App\Services\Dashboard\Support\BranchScope;
use App\Services\Dashboard\Support\MonthBucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "is anything wrong right now" tab.
 *
 * Cash flow and debt aging give the shape of the month; the alert strip and
 * the activity feed give the two things a reader opens a dashboard for — what
 * needs attention, and what just happened.
 */
class OverviewStats
{
    /**
     * Available quantity at or below this counts as low stock.
     *
     * There is no per-catalog reorder threshold in the schema, so the
     * dashboard applies one flat rule rather than inventing a column.
     */
    private const LOW_STOCK_THRESHOLD = 2;

    private const UNPAID_ALERT_DAYS = 60;

    public function get(DashboardFilters $filters): array
    {
        return [
            'cashFlow' => $this->cashFlow($filters),
            'debtAging' => $this->debtAging($filters),
            'alerts' => $this->alerts($filters),
            'activity' => $this->activity($filters),
        ];
    }

    private function cashFlow(DashboardFilters $filters): array
    {
        $labels = MonthBucket::lastTwelve($filters->anchor());
        $bucket = MonthBucket::expression('transaction_date');

        $rows = BranchScope::transactions(
            Transaction::query()->where('archived', false),
            $filters->branchId,
        )
            ->selectRaw("{$bucket} as bucket, transaction_type, SUM(amount) as total")
            ->groupBy('bucket', 'transaction_type')
            ->get();

        $income = MonthBucket::series(
            $rows->where('transaction_type', 'income')->pluck('total', 'bucket')->all(),
            $labels,
        );
        $expense = MonthBucket::series(
            $rows->where('transaction_type', 'expense')->pluck('total', 'bucket')->all(),
            $labels,
        );

        return [
            'labels' => $labels,
            'income' => $income,
            'expense' => $expense,
            'net' => array_map(
                fn (float $in, float $out): float => round($in - $out, 2),
                $income,
                $expense,
            ),
        ];
    }

    /**
     * Unpaid debt split by how long it has been overdue.
     *
     * All four buckets are always returned, empty ones included, so the chart
     * keeps a stable shape instead of rearranging itself as data arrives.
     */
    private function debtAging(DashboardFilters $filters): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        $buckets = [
            '0-30' => [$today->subDays(30), $today],
            '31-60' => [$today->subDays(60), $today->subDays(31)],
            '61-90' => [$today->subDays(90), $today->subDays(61)],
            '90+' => [null, $today->subDays(91)],
        ];

        $rows = $this->unpaidLines($filters)
            ->selectRaw(
                $this->effectiveDueDate().' as due, player_subscriptions.player_id as player_id, '
                .'(amount_owed - amount_paid) as unpaid'
            )
            ->get();

        return collect($buckets)->map(function (array $window, string $label) use ($rows): array {
            [$start, $end] = $window;

            $matching = $rows->filter(function (object $row) use ($start, $end): bool {
                $due = CarbonImmutable::parse($row->due);

                return ($start === null || $due->gte($start)) && $due->lte($end);
            });

            return [
                'bucket' => $label,
                'amount' => round((float) $matching->sum('unpaid'), 2),
                'players' => $matching->pluck('player_id')->unique()->count(),
            ];
        })->values()->all();
    }

    /** Non-exempt subscription lines with something still owed, for active players. */
    private function unpaidLines(DashboardFilters $filters): Builder
    {
        $query = DB::table('player_subscriptions')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->where('players.archived', false)
            ->where('player_subscriptions.is_exempt', false)
            ->whereRaw('(amount_owed - amount_paid) > 0');

        if ($branchIds = BranchScope::playerIdsQuery($filters->branchId)) {
            $query->whereIn('player_subscriptions.player_id', $branchIds);
        }

        return $query;
    }

    private function effectiveDueDate(): string
    {
        return "COALESCE(player_subscriptions.due_date, (player_subscriptions.year || '-12-31'))";
    }

    /**
     * Only alerts with something to report are returned.
     *
     * A strip of green "all clear" chips trains the reader to stop looking at
     * the strip, which is exactly the wrong habit for the one component whose
     * job is to be noticed.
     */
    private function alerts(DashboardFilters $filters): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        $overdueRentals = EquipmentRental::query()
            ->whereNull('return_date')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->whereHas('equipmentItem.branches', fn ($b) => $b->whereKey($filters->branchId)),
            )
            ->count();

        $lowStock = $this->lowStockCount($filters);

        $negativeAccounts = DB::table('finance_accounts')
            ->where('is_active', true)
            ->where('current_balance', '<', 0)
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->count();

        $unpaidMembers = $this->unpaidLines($filters)
            ->whereRaw($this->effectiveDueDate().' < ?', [$today->subDays(self::UNPAID_ALERT_DAYS)->toDateString()])
            ->distinct()
            ->count('player_subscriptions.player_id');

        // Route names, resolved client-side by Ziggy. They are asserted below
        // so a renamed route fails a test here rather than throwing in the
        // browser when an alert finally has something to report.
        return collect([
            ['key' => 'overdue_rentals', 'count' => $overdueRentals, 'severity' => 'serious', 'href' => 'equipment.catalogs.index'],
            ['key' => 'low_stock', 'count' => $lowStock, 'severity' => 'warning', 'href' => 'equipment.catalogs.index'],
            ['key' => 'negative_balance', 'count' => $negativeAccounts, 'severity' => 'critical', 'href' => 'finance.index'],
            ['key' => 'unpaid_over_60', 'count' => $unpaidMembers, 'severity' => 'warning', 'href' => 'players.index'],
        ])->filter(fn (array $alert): bool => $alert['count'] > 0)->values()->all();
    }

    /**
     * Catalogs whose available quantity has fallen to the threshold.
     *
     * Available means stocked quantity minus what is currently out on loan.
     */
    private function lowStockCount(DashboardFilters $filters): int
    {
        $outstanding = DB::table('equipment_rentals')
            ->whereNull('return_date')
            ->selectRaw('equipment_item_id, COALESCE(SUM(quantity - returned_quantity), 0) as out')
            ->groupBy('equipment_item_id');

        return DB::table('equipment_items')
            ->leftJoinSub($outstanding, 'r', 'r.equipment_item_id', '=', 'equipment_items.id')
            ->whereNotIn('equipment_items.status', ['Retired', 'Out of Service', 'Lost'])
            ->when($filters->branchId !== null, fn ($q) => $q->whereIn(
                'equipment_items.id',
                DB::table('branch_equipment_item')
                    ->where('branch_id', $filters->branchId)
                    ->select('equipment_item_id'),
            ))
            ->groupBy('equipment_items.catalog_id')
            ->havingRaw('SUM(equipment_items.quantity - COALESCE(r.out, 0)) <= ?', [self::LOW_STOCK_THRESHOLD])
            ->select('equipment_items.catalog_id')
            ->get()
            ->count();
    }

    /**
     * The last ten things that happened, across sources.
     *
     * Each source is capped before the merge so one busy table cannot crowd
     * the others out of the feed.
     *
     * Deliberately on the query builder rather than Eloquent: EquipmentRental
     * appends `recipient_name`, whose accessor loads the polymorphic rentable
     * one row at a time. Hydrating ten rentals as models costs ten extra
     * queries for a label this feed does not use.
     */
    private function activity(DashboardFilters $filters): array
    {
        $transactions = DB::table('transactions')
            ->where('transactions.archived', false)
            ->when($filters->branchId !== null, fn ($q) => $q->whereIn(
                'transactions.finance_account_id',
                DB::table('finance_accounts')->where('branch_id', $filters->branchId)->select('id'),
            ))
            ->orderByDesc('transaction_date')
            ->limit(10)
            ->get(['id', 'transaction_date', 'amount', 'transaction_type', 'category', 'title', 'description'])
            ->map(fn (object $t): array => [
                'type' => 'transaction',
                'at' => (string) $t->transaction_date,
                'label' => $t->title ?: ($t->description ?: $t->category),
                'amount' => $t->transaction_type === 'income' ? (float) $t->amount : -(float) $t->amount,
                'id' => $t->id,
            ]);

        $registrations = DB::table('players')
            ->where('archived', false)
            ->when($filters->branchId !== null, fn ($q) => $q->whereIn(
                'players.id',
                DB::table('branch_player')->where('branch_id', $filters->branchId)->select('player_id'),
            ))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'created_at', 'firstname', 'lastname'])
            ->map(fn (object $p): array => [
                'type' => 'registration',
                'at' => (string) $p->created_at,
                'label' => trim("{$p->firstname} {$p->lastname}"),
                'amount' => null,
                'id' => $p->id,
            ]);

        $rentals = DB::table('equipment_rentals')
            ->join('equipment_items', 'equipment_items.id', '=', 'equipment_rentals.equipment_item_id')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->when($filters->branchId !== null, fn ($q) => $q->whereIn(
                'equipment_items.id',
                DB::table('branch_equipment_item')
                    ->where('branch_id', $filters->branchId)
                    ->select('equipment_item_id'),
            ))
            ->orderByDesc('equipment_rentals.checkout_date')
            ->limit(10)
            ->get([
                'equipment_rentals.id as id',
                'equipment_rentals.checkout_date as checkout_date',
                'equipment_catalogs.name as catalog_name',
            ])
            ->map(fn (object $r): array => [
                'type' => 'rental',
                'at' => (string) $r->checkout_date,
                'label' => (string) $r->catalog_name,
                'amount' => null,
                'id' => $r->id,
            ]);

        return Collection::make()
            ->concat($transactions)
            ->concat($registrations)
            ->concat($rentals)
            ->filter(fn (array $entry): bool => $entry['at'] !== '')
            ->sortByDesc('at')
            ->take(10)
            ->values()
            ->all();
    }
}
