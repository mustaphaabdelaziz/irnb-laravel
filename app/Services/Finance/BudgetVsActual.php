<?php

namespace App\Services\Finance;

use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * Planned against actual per finance category for one fiscal year — the
 * Finance page's budget card and the budget report read this one figure.
 * Actual = counted (non-archived) entries of the season; variance is in the
 * club's favour when positive (income over plan, expense under plan).
 */
final class BudgetVsActual
{
    /** @return list<array{category_id: int, name: ?string, type: string, color: ?string, planned: float, actual: float, variance: float}> */
    public static function for(FiscalYear $fy): array
    {
        $actuals = DB::table('transactions')
            ->where('archived', false)
            ->where('fiscal_year', $fy->year)
            ->groupBy('transaction_type', 'finance_category_id')
            ->selectRaw('transaction_type, finance_category_id, SUM(amount) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->transaction_type.'|'.$row->finance_category_id => $row->total]);
        $budgets = Budget::where('fiscal_year_id', $fy->id)->pluck('planned_amount', 'finance_category_id');

        return FinanceCategory::where('is_active', true)->orderBy('type')->orderBy('sort_order')->get()
            ->map(function (FinanceCategory $c) use ($budgets, $actuals) {
                $planned = (float) ($budgets[$c->id] ?? 0);
                // Entries on the category's own side of the books.
                $actual = (float) ($actuals[$c->type.'|'.$c->id] ?? 0);

                return [
                    'category_id' => $c->id, 'name' => $c->localized_name, 'type' => $c->type, 'color' => $c->color,
                    'planned' => $planned, 'actual' => $actual,
                    'variance' => $c->type === 'expense' ? $planned - $actual : $actual - $planned,
                ];
            })
            ->filter(fn ($r) => $r['planned'] > 0 || $r['actual'] > 0)
            ->values()
            ->all();
    }
}
