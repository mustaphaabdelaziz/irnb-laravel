<?php

namespace App\Services\Dashboard;

use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use App\Services\Dashboard\Support\BranchScope;
use App\Support\FinanceAccountLabel;
use App\Support\UiLang;
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
            ->where('transactions.transaction_type', 'expense')
            ->with('financeCategory:id,name,name_ar,name_fr,name_en')
            ->orderByDesc('transactions.amount')
            ->first([
                'transactions.id', 'transactions.amount', 'transactions.title', 'transactions.description',
                'transactions.finance_category_id', 'transactions.transaction_date',
            ]);

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
                    // Never the stored slug: title, description, the category's name, else "Expense".
                    'label' => $largest->title ?: ($largest->description ?: ($largest->financeCategory?->localized_name ?: UiLang::get('expense'))),
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

    /**
     * Active accounts, labelled like the Caisses page. The Fonds Initial is
     * bookkeeping rather than a place money sits: listed only while it still
     * holds some, so no money disappears from view.
     */
    private function accounts(DashboardFilters $filters): array
    {
        return FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->where('is_active', true)
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->orderByDesc('is_treasury')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->reject(fn (FinanceAccount $a): bool => $a->is_opening_fund && abs((float) $a->current_balance) < 0.005)
            ->map(fn (FinanceAccount $a): array => [
                'id' => $a->id,
                'name' => (string) $a->name,
                'label' => FinanceAccountLabel::for($a),
                'branch' => $a->branch?->localized_name,
                'is_treasury' => (bool) $a->is_treasury,
                'opening' => round((float) $a->opening_balance, 2),
                'current' => round((float) $a->current_balance, 2),
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

        $recentIds = (clone $query)
            ->orderByDesc('finance_transfers.transfer_date')
            ->orderByDesc('finance_transfers.id')
            ->limit(5)
            ->pluck('finance_transfers.id');

        $labelled = ['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'];
        $recent = FinanceTransfer::query()
            ->with(['fromAccount' => fn ($q) => $q->with($labelled), 'toAccount' => fn ($q) => $q->with($labelled)])
            ->whereIn('id', $recentIds)
            ->orderByDesc('transfer_date')->orderByDesc('id')
            ->get()
            ->map(fn (FinanceTransfer $tr): array => [
                'id' => $tr->id,
                'from' => FinanceAccountLabel::for($tr->fromAccount),
                'to' => FinanceAccountLabel::for($tr->toAccount),
                'amount' => round((float) $tr->amount, 2),
                'date' => $tr->transfer_date?->toDateString() ?? '',
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
