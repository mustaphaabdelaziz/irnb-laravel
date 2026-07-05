<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1990', 'max:2200', 'unique:fiscal_years,year'],
            'opening_balance' => ['nullable', 'numeric'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        FiscalYear::create([
            'year' => $data['year'],
            'label' => $data['label'] ?? null,
            'start_date' => $data['year'].'-01-01',
            'end_date' => $data['year'].'-12-31',
            'status' => 'open',
            'opening_balance' => $data['opening_balance'] ?? 0,
        ]);

        return back()->with('success', "Fiscal year {$data['year']} created.");
    }

    public function update(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        abort_if($fiscalYear->isClosed(), 403, 'A closed year cannot be edited. Reopen it first.');

        $data = $request->validate([
            'opening_balance' => ['required', 'numeric'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $fiscalYear->update($data);
        $this->finance->recomputeYear($fiscalYear);

        return back()->with('success', 'Fiscal year updated.');
    }

    public function close(FiscalYear $fiscalYear, Request $request): RedirectResponse
    {
        $this->finance->closeYear($fiscalYear, $request->user());

        return back()->with('success', "Year {$fiscalYear->year} closed. Balance carried forward to ".($fiscalYear->year + 1).'.');
    }

    public function reopen(FiscalYear $fiscalYear): RedirectResponse
    {
        $this->finance->reopenYear($fiscalYear);

        return back()->with('success', "Year {$fiscalYear->year} reopened.");
    }
}
