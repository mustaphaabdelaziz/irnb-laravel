<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FiscalYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    /**
     * Bulk set the planned amounts for a fiscal year's categories.
     */
    public function update(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $data = $request->validate([
            'budgets' => ['present', 'array'],
            'budgets.*.finance_category_id' => ['required', 'integer', 'exists:finance_categories,id'],
            'budgets.*.planned_amount' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data['budgets'] as $row) {
            if ((float) $row['planned_amount'] <= 0) {
                Budget::where('fiscal_year_id', $fiscalYear->id)
                    ->where('finance_category_id', $row['finance_category_id'])->delete();

                continue;
            }

            Budget::updateOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'finance_category_id' => $row['finance_category_id']],
                ['planned_amount' => $row['planned_amount']]
            );
        }

        return back()->with('success', 'Budget saved.');
    }
}
