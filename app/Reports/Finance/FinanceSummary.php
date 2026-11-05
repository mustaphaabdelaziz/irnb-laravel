<?php

namespace App\Reports\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Reports\Column;
use App\Reports\Concerns\FiltersTransactions;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Dashboard\ModuleStats;
use App\Services\Dashboard\Support\MonthBucket;
use App\Support\FinanceAccountLabel;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * Income and expenses for a period, by finance category, with the same
 * population as the Transactions screen (FiltersTransactions).
 * Cash basis: subscriptions owed are debts, not income, so they are absent.
 */
class FinanceSummary extends Report
{
    use FiltersTransactions;

    public function __construct(private readonly ModuleStats $stats) {}

    public function key(): string
    {
        return 'summary';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function module(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return 'money';
    }

    public function filters(): array
    {
        return $this->transactionFilters();
    }

    public function columns(): array
    {
        return [
            new Column('category', 'col.category'),
            new Column('type', 'col.type'),
            new Column('entries', 'reports.col.entries', Column::NUMBER, true),
            new Column('income', 'reports.col.income', Column::MONEY, true),
            new Column('expense', 'reports.col.expense', Column::MONEY, true),
        ];
    }

    public function sortable(): array
    {
        return ['category' => 'category', 'entries' => 'entries', 'income' => 'income', 'expense' => 'expense'];
    }

    public function landscape(): bool
    {
        return false;
    }

    /** One line per side of the books and finance category, income first, largest first. */
    public function rows(ReportFilters $filters): Collection
    {
        $groups = $this->transactions($filters)->toBase()
            ->groupBy('transaction_type', 'finance_category_id')
            ->selectRaw('transaction_type, finance_category_id, COUNT(*) as entries, SUM(amount) as total')
            ->get();
        $names = FinanceCategory::whereIn('id', $groups->pluck('finance_category_id')->filter())->get()->keyBy('id');

        return $groups
            ->sortBy([['transaction_type', 'desc'], fn ($a, $b) => $b->total <=> $a->total])
            ->map(fn ($g) => [
                'category' => $names->get($g->finance_category_id)?->localized_name ?? UiLang::get('reports.uncategorised'),
                'type' => UiLang::get((string) $g->transaction_type, $g->transaction_type),
                'entries' => (int) $g->entries,
                'income' => $g->transaction_type === 'income' ? round((float) $g->total, 2) : null,
                'expense' => $g->transaction_type === 'expense' ? round((float) $g->total, 2) : null,
            ])
            ->values();
    }

    public function totals(ReportFilters $filters): array
    {
        [$income, $expense] = $this->incomeExpense($filters);

        return [
            'entries' => $this->transactions($filters)->count(),
            'income' => $income,
            'expense' => $expense,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        [$income, $expense, $net] = $this->incomeExpense($filters);

        return [
            ['label' => 'reports.kpi.income', 'value' => $income, 'format' => 'money'],
            ['label' => 'reports.kpi.expense', 'value' => $expense, 'format' => 'money'],
            ['label' => 'reports.kpi.net', 'value' => $net, 'format' => 'money'],
            ['label' => 'reports.kpi.entries', 'value' => $this->transactions($filters)->count(), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $months = $this->transactions($filters)->toBase()
            ->selectRaw(MonthBucket::expression('transaction_date').' as month, transaction_type, SUM(amount) as total')
            ->groupBy('month', 'transaction_type')->orderBy('month')->get();

        $byMethod = $this->transactions($filters)->toBase()
            ->selectRaw('payment_method, SUM(amount) as total')->where('transaction_type', 'income')
            ->groupBy('payment_method')->orderByDesc('total')->get();

        $byAccount = $this->transactions($filters)->toBase()
            ->selectRaw('finance_account_id, SUM(amount) as total')->where('transaction_type', 'income')
            ->groupBy('finance_account_id')->orderByDesc('total')->get();
        $accounts = FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->whereIn('id', $byAccount->pluck('finance_account_id')->filter())->get()->keyBy('id');

        $monthly = fn (string $type) => $months->where('transaction_type', $type)->map(fn ($m) => [
            'key' => $m->month, 'label' => $m->month, 'count' => round((float) $m->total, 2),
        ])->values()->all();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.income_by_month', 'stats' => $monthly('income'), 'unit' => 'money'],
            ['type' => 'bar', 'title' => 'reports.chart.expense_by_month', 'stats' => $monthly('expense'), 'unit' => 'money'],
            ['type' => 'doughnut', 'title' => 'reports.chart.income_by_method', 'stats' => $byMethod->map(fn ($r) => [
                'key' => (string) ($r->payment_method ?? 'none'),
                'label' => $this->methodLabel($r->payment_method) ?? UiLang::get('reports.none'),
                'count' => round((float) $r->total, 2),
            ])->all(), 'unit' => 'money'],
            ['type' => 'bar', 'title' => 'reports.chart.income_by_caisse', 'stats' => $byAccount->map(fn ($r) => [
                'key' => (int) $r->finance_account_id,
                'label' => FinanceAccountLabel::for($accounts->get($r->finance_account_id)) ?: UiLang::get('reports.none'),
                'count' => round((float) $r->total, 2),
            ])->all(), 'unit' => 'money'],
        ];
    }

    /**
     * The Transactions screen's own income / expense / net tiles, so the two
     * pages can never disagree.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function incomeExpense(ReportFilters $filters): array
    {
        $tiles = $this->stats->transactions($this->transactions($filters));

        return [(float) $tiles[0]['value'], (float) $tiles[1]['value'], (float) $tiles[2]['value']];
    }
}
