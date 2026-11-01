<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Support\ClubCurrency;
use Carbon\CarbonImmutable;

/**
 * Cash-basis summary of the period: money actually received and spent.
 * Same population as the transactions screen (archived = cancelled entries
 * excluded); transfers live in their own table; unpaid subscriptions are
 * debts, not income, until paid.
 */
final class FinanceSection implements Section
{
    private const TOP = 5;

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = Transaction::query()
            ->where('archived', false)
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString());

        $byCategory = (clone $base)->toBase()
            ->selectRaw('transaction_type, finance_category_id, SUM(amount) as total')
            ->groupBy('transaction_type', 'finance_category_id')
            ->get();
        $names = FinanceCategory::query()
            ->whereIn('id', $byCategory->pluck('finance_category_id')->filter()->unique())
            ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])
            ->keyBy('id');

        $top = fn (string $type) => $byCategory->where('transaction_type', $type)
            ->sortByDesc(fn ($row) => (float) $row->total)
            ->take(self::TOP)
            ->map(fn ($row) => [
                'name' => $row->finance_category_id ? $names->get($row->finance_category_id)?->localized_name : null,
                'total' => round((float) $row->total, 2),
            ])->values()->all();

        $income = round((float) $byCategory->where('transaction_type', 'income')->sum(fn ($row) => (float) $row->total), 2);
        $expense = round((float) $byCategory->where('transaction_type', 'expense')->sum(fn ($row) => (float) $row->total), 2);

        return [
            'income' => $income,
            'expense' => $expense,
            'balance' => round($income - $expense, 2),
            'currency' => ClubCurrency::label(),
            'top_income' => $top('income'),
            'top_expense' => $top('expense'),
        ];
    }
}
