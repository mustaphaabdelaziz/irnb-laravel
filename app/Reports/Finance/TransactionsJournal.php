<?php

namespace App\Reports\Finance;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Reports\Column;
use App\Reports\Concerns\FiltersTransactions;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Dashboard\ModuleStats;
use App\Support\FinanceAccountLabel;
use App\Support\TransactionTitle;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every counted entry of a period, dated, titled and filed like on the
 * Transactions screen; income and expenses in their own columns so the
 * totals row never adds one to the other.
 */
class TransactionsJournal extends Report
{
    use FiltersTransactions;

    public function __construct(private readonly ModuleStats $stats) {}

    public function key(): string
    {
        return 'journal';
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
        return 'transactions';
    }

    public function filters(): array
    {
        return [
            Filter::select('type', 'col.type', [
                ['value' => 'income', 'label' => UiLang::get('income')],
                ['value' => 'expense', 'label' => UiLang::get('expense')],
            ]),
            Filter::multi('finance_category_id', 'col.category', FinanceCategory::query()
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (FinanceCategory $c) => ['value' => $c->id, 'label' => $c->localized_name])->all()),
            ...$this->transactionFilters(),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'col.date', Column::DATE),
            new Column('title', 'col.title'),
            new Column('category', 'col.category'),
            new Column('method', 'col.payment_method'),
            new Column('caisse', 'col.cash_register'),
            new Column('income', 'reports.col.income', Column::MONEY, true),
            new Column('expense', 'reports.col.expense', Column::MONEY, true),
            new Column('recorded_by', 'col.recorded_by'),
        ];
    }

    public function sortable(): array
    {
        return ['date' => 'transaction_date', 'income' => "CASE WHEN transaction_type = 'income' THEN amount END", 'expense' => "CASE WHEN transaction_type = 'expense' THEN amount END"];
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        return $this->base($filters)->with(['recordedBy:id,name', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL]);
    }

    /** @param Transaction $record */
    public function row(mixed $record): array
    {
        $amount = round((float) $record->amount, 2);

        return [
            'date' => substr((string) $record->transaction_date, 0, 10),
            'title' => TransactionTitle::for($record),
            'category' => $record->financeCategory?->localized_name ?? UiLang::get('reports.uncategorised'),
            'method' => $this->methodLabel($record->payment_method),
            'caisse' => FinanceAccountLabel::for($record->financeAccount) ?: null,
            'income' => $record->transaction_type === 'income' ? $amount : null,
            'expense' => $record->transaction_type === 'expense' ? $amount : null,
            'recorded_by' => $record->recordedBy?->name,
        ];
    }

    public function totals(ReportFilters $filters): array
    {
        $tiles = $this->stats->transactions($this->base($filters));

        return ['income' => (float) $tiles[0]['value'], 'expense' => (float) $tiles[1]['value']];
    }

    public function summary(ReportFilters $filters): array
    {
        $tiles = $this->stats->transactions($this->base($filters));

        return [
            ['label' => 'reports.kpi.entries', 'value' => $this->base($filters)->count(), 'format' => 'number'],
            ['label' => 'reports.kpi.income', 'value' => (float) $tiles[0]['value'], 'format' => 'money'],
            ['label' => 'reports.kpi.expense', 'value' => (float) $tiles[1]['value'], 'format' => 'money'],
            ['label' => 'reports.kpi.net', 'value' => (float) $tiles[2]['value'], 'format' => 'money'],
        ];
    }

    /** @return Builder<Transaction> */
    private function base(ReportFilters $filters): Builder
    {
        $query = $this->transactions($filters);
        if ($filters->filled('type')) {
            $query->where('transaction_type', $filters->get('type'));
        }
        if ($filters->filled('finance_category_id')) {
            $query->whereIn('finance_category_id', $filters->get('finance_category_id'));
        }

        return $query;
    }
}
