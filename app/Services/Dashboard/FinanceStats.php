<?php

namespace App\Services\Dashboard;

use App\Models\Transaction;
use App\Services\Dashboard\Support\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where the money went, where it came from, and how long it lasts.
 *
 * Transfers appear here as their own figure and are kept out of every income,
 * expense and category total: moving a dinar from one register to another is
 * not earning or spending it, and counting it as either inflates both sides of
 * the books.
 */
class FinanceStats
{
    /** Category rows shown before the tail is folded into "Other". */
    private const CATEGORY_ROWS = 8;

    public function get(DashboardFilters $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'expenseByCategory' => $this->byCategory($filters, 'expense'),
            'incomeByCategory' => $this->byCategory($filters, 'income'),
            'accounts' => $this->accounts($filters),
            'transfers' => $this->transfers($filters),
        ];
    }

    private function summary(DashboardFilters $filters): array
    {
        $row = $this->windowed($filters)
            ->selectRaw(
                'COUNT(*) as entries, '
                ."SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as expense_total, "
                .'SUM(amount) as amount_total'
            )
            ->first();

        $entries = (int) ($row->entries ?? 0);
        $average = $entries > 0 ? round((float) $row->amount_total / $entries, 2) : null;

        $largest = $this->windowed($filters)
            ->where('transaction_type', 'expense')
            ->orderByDesc('amount')
            ->first(['id', 'amount', 'description', 'category', 'transaction_date']);

        $burnRate = $this->burnRate($filters, (float) ($row->expense_total ?? 0));
        $treasury = $this->treasuryBalance($filters);

        return [
            [
                'key' => 'avg_transaction',
                'value' => $average,
                'format' => 'money',
                'meta' => $entries > 0 ? ['entries' => $entries] : null,
            ],
            [
                'key' => 'largest_expense',
                'value' => $largest ? round((float) $largest->amount, 2) : null,
                'format' => 'money',
                'meta' => $largest ? [
                    'label' => $largest->description ?: $largest->category,
                    'date' => $largest->transaction_date?->toDateString(),
                ] : null,
            ],
            [
                'key' => 'burn_rate',
                'value' => $burnRate,
                'format' => 'money',
                'meta' => null,
            ],
            [
                // Null, not infinity: a club that spent nothing this period has
                // no meaningful runway, and "∞ months" is not a finding.
                'key' => 'runway',
                'value' => $burnRate !== null && $burnRate > 0
                    ? round($treasury / $burnRate, 1)
                    : null,
                'format' => 'months',
                'meta' => null,
            ],
        ];
    }

    /**
     * Mean monthly spend across the window.
     *
     * The total alone answers "how much did we spend"; the dashboard needs
     * "how fast are we spending", which only means something per month.
     */
    private function burnRate(DashboardFilters $filters, float $expenseTotal): ?float
    {
        if ($expenseTotal <= 0.0) {
            return null;
        }

        $months = $this->monthsInWindow($filters);

        return $months > 0 ? round($expenseTotal / $months, 2) : null;
    }

    private function monthsInWindow(DashboardFilters $filters): int
    {
        if ($filters->from === null) {
            $earliest = $this->windowed($filters)->min('transaction_date');

            if ($earliest === null) {
                return 1;
            }

            return max(1, CarbonImmutable::parse($earliest)->startOfMonth()->diffInMonths(
                CarbonImmutable::now()->startOfMonth()
            ) + 1);
        }

        return max(1, $filters->from->startOfMonth()->diffInMonths($filters->to->startOfMonth()) + 1);
    }

    /**
     * Every dinar the club holds, counted once.
     *
     * Root accounts only: a treasury's current_balance already rolls up its
     * child registers, so summing every row double-counts a branch's cash.
     * Filtering to `is_treasury` instead would miss the club-wide registers
     * that sit at the root without being a treasury.
     */
    private function treasuryBalance(DashboardFilters $filters): float
    {
        return (float) DB::table('finance_accounts')
            ->where('is_active', true)
            ->whereNull('parent_account_id')
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->sum('current_balance');
    }

    /**
     * One side of the books split by finance category.
     *
     * Magnitude, not identity — the chart draws these in one sequential hue,
     * so the row order carries the meaning and the tail can fold into "Other"
     * without losing a colour's worth of information.
     */
    private function byCategory(DashboardFilters $filters, string $type): array
    {
        // The locale column is picked here rather than reading the model's
        // localized_name accessor, because hydrating a model per category to
        // read one string would undo the point of aggregating in SQL. Falls
        // back to the base name, exactly as the accessor does.
        $localeColumn = 'finance_categories.name_'.app()->getLocale();

        $rows = $this->windowed($filters)
            ->leftJoin('finance_categories', 'finance_categories.id', '=', 'transactions.finance_category_id')
            ->where('transactions.transaction_type', $type)
            ->groupBy('finance_categories.id', 'finance_categories.name', $localeColumn)
            ->orderByDesc('total')
            ->selectRaw(
                "COALESCE(NULLIF({$localeColumn}, ''), finance_categories.name) as name, "
                .'SUM(transactions.amount) as total'
            )
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $grandTotal = (float) $rows->sum('total');

        $head = $rows->take(self::CATEGORY_ROWS);
        $tail = $rows->slice(self::CATEGORY_ROWS);

        // `labelKey` carries the two rows the dashboard names itself rather
        // than reading from the database. They are keys, not English, so they
        // translate with the rest of the interface.
        $result = $head->map(fn (object $row): array => [
            'name' => $row->name ?: null,
            'labelKey' => $row->name ? null : 'uncategorised',
            'amount' => round((float) $row->total, 2),
            'share' => $grandTotal > 0 ? round((float) $row->total / $grandTotal * 100, 1) : 0.0,
        ])->values()->all();

        if ($tail->isNotEmpty()) {
            $tailTotal = (float) $tail->sum('total');

            $result[] = [
                'name' => null,
                'labelKey' => 'other',
                'amount' => round($tailTotal, 2),
                'share' => $grandTotal > 0 ? round($tailTotal / $grandTotal * 100, 1) : 0.0,
                'folded' => $tail->count(),
            ];
        }

        return $result;
    }

    private function accounts(DashboardFilters $filters): array
    {
        return DB::table('finance_accounts')
            ->leftJoin('branches', 'branches.id', '=', 'finance_accounts.branch_id')
            ->where('finance_accounts.is_active', true)
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->where('finance_accounts.branch_id', $filters->branchId),
            )
            ->orderByDesc('finance_accounts.is_treasury')
            ->orderBy('finance_accounts.sort_order')
            ->orderBy('finance_accounts.id')
            ->get([
                'finance_accounts.id as id',
                'finance_accounts.name as name',
                'finance_accounts.is_treasury as is_treasury',
                'finance_accounts.opening_balance as opening',
                'finance_accounts.current_balance as current',
                'branches.name as branch',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'branch' => $row->branch,
                'is_treasury' => (bool) $row->is_treasury,
                'opening' => round((float) $row->opening, 2),
                'current' => round((float) $row->current, 2),
            ])
            ->values()
            ->all();
    }

    private function transfers(DashboardFilters $filters): array
    {
        $query = DB::table('finance_transfers')
            ->join('finance_accounts as source', 'source.id', '=', 'finance_transfers.from_account_id')
            ->join('finance_accounts as target', 'target.id', '=', 'finance_transfers.to_account_id')
            ->when($filters->branchId !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('source.branch_id', $filters->branchId)
                    ->orWhere('target.branch_id', $filters->branchId),
            ));

        if ($filters->from !== null) {
            $query->whereBetween('finance_transfers.transfer_date', [
                $filters->from->toDateString(),
                $filters->to->toDateString(),
            ]);
        }

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as entries, SUM(finance_transfers.amount) as total')
            ->first();

        $recent = (clone $query)
            ->orderByDesc('finance_transfers.transfer_date')
            ->limit(5)
            ->get([
                'finance_transfers.id as id',
                'finance_transfers.amount as amount',
                'finance_transfers.transfer_date as date',
                'source.name as source_name',
                'target.name as target_name',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'from' => (string) $row->source_name,
                'to' => (string) $row->target_name,
                'amount' => round((float) $row->amount, 2),
                'date' => (string) $row->date,
            ])
            ->values()
            ->all();

        return [
            'count' => (int) ($totals->entries ?? 0),
            'total' => round((float) ($totals->total ?? 0), 2),
            'recent' => $recent,
        ];
    }

    /** Non-archived transactions inside the window, branch-scoped. */
    private function windowed(DashboardFilters $filters): Builder
    {
        $query = BranchScope::transactions(
            Transaction::query()->where('transactions.archived', false),
            $filters->branchId,
        );

        if ($filters->from !== null) {
            $query->whereBetween('transactions.transaction_date', [$filters->from, $filters->to]);
        }

        return $query;
    }
}
