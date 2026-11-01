<?php

namespace App\Reports\Finance;

use App\Http\Controllers\TransactionController;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Dashboard\ModuleStats;
use App\Support\FinanceAccountLabel;
use App\Support\FiscalPeriod;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Income and expenses for a period, by finance category, with the same
 * population as the Transactions screen: cancelled (archived) entries never
 * count, and with no season and no dates the current season applies.
 * Cash basis: subscriptions owed are debts, not income, so they are absent.
 */
class FinanceSummary extends Report
{
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
        $seasons = FiscalYear::query()->pluck('year')->map(fn ($y) => (int) $y)
            ->push(FiscalPeriod::currentKey())->unique()->sortDesc()->values();

        $methods = Transaction::query()->where('archived', false)->whereNotNull('payment_method')
            ->distinct()->orderBy('payment_method')->pluck('payment_method');

        return [
            Filter::select('season', 'reports.filter.season', $seasons
                ->map(fn (int $key) => ['value' => $key, 'label' => FiscalPeriod::label($key)])->all()),
            Filter::date('from', 'reports.filter.from'),
            Filter::date('to', 'reports.filter.to'),
            Filter::multi('account_id', 'col.cash_register', FinanceAccount::query()
                ->where('is_opening_fund', false)
                ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
                ->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (FinanceAccount $a) => ['value' => $a->id, 'label' => FinanceAccountLabel::for($a)])->all()),
            Filter::multi('payment_method', 'col.payment_method', $methods
                ->map(fn (string $m) => ['value' => $m, 'label' => $this->methodLabel($m)])->all()),
        ];
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
        $groups = $this->base($filters)->toBase()
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
            'entries' => $this->base($filters)->count(),
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
            ['label' => 'reports.kpi.entries', 'value' => $this->base($filters)->count(), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        // SUBSTR works on SQLite (tests, desktop) and MySQL (web) alike.
        $months = $this->base($filters)->toBase()
            ->selectRaw('SUBSTR(transaction_date, 1, 7) as month, transaction_type, SUM(amount) as total')
            ->groupBy('month', 'transaction_type')->orderBy('month')->get();

        $byMethod = $this->base($filters)->toBase()
            ->selectRaw('payment_method, SUM(amount) as total')->where('transaction_type', 'income')
            ->groupBy('payment_method')->orderByDesc('total')->get();

        $byAccount = $this->base($filters)->toBase()
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
                'label' => $r->payment_method ? $this->methodLabel($r->payment_method) : UiLang::get('reports.none'),
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
        $tiles = $this->stats->transactions($this->base($filters));

        return [(float) $tiles[0]['value'], (float) $tiles[1]['value'], (float) $tiles[2]['value']];
    }

    /** @return Builder<Transaction> */
    private function base(ReportFilters $filters): Builder
    {
        $query = Transaction::query()->where('archived', false);

        if ($filters->filled('from')) {
            $query->whereDate('transaction_date', '>=', $filters->get('from'));
        }
        if ($filters->filled('to')) {
            $query->whereDate('transaction_date', '<=', $filters->get('to'));
        }
        // As on Transactions: a chosen season always applies; with none, the
        // current season applies unless a date range already says which days.
        if ($filters->filled('season')) {
            $query->where('fiscal_year', $filters->get('season'));
        } elseif (! $filters->filled('from') && ! $filters->filled('to')) {
            $query->where('fiscal_year', FiscalPeriod::currentKey());
        }
        if ($filters->filled('account_id')) {
            $query->whereIn('finance_account_id', $filters->get('account_id'));
        }
        if ($filters->filled('payment_method')) {
            $query->whereIn('payment_method', $filters->get('payment_method'));
        }

        return $query;
    }

    private function methodLabel(string $code): string
    {
        return UiLang::get(TransactionController::PAYMENT_METHOD_KEYS[$code] ?? $code, $code);
    }
}
