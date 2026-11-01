<?php

namespace App\Reports\Concerns;

use App\Http\Controllers\TransactionController;
use App\Models\FinanceAccount;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Reports\Filter;
use App\Reports\ReportFilters;
use App\Support\FinanceAccountLabel;
use App\Support\FiscalPeriod;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;

/**
 * Transaction filters shared by the finance reports, with the Transactions
 * screen's population: cancelled (archived) entries never count, and with no
 * season and no dates the current season applies.
 */
trait FiltersTransactions
{
    /** @return list<Filter> season, from, to, caisse, payment method */
    protected function transactionFilters(): array
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

    /** @return Builder<Transaction> */
    protected function transactions(ReportFilters $filters): Builder
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

    protected function methodLabel(?string $code): ?string
    {
        return $code === null ? null : UiLang::get(TransactionController::PAYMENT_METHOD_KEYS[$code] ?? $code, $code);
    }
}
