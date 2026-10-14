<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'start_date' => FiscalPeriod::start($data['year'])->toDateString(),
            'end_date' => FiscalPeriod::end($data['year'])->toDateString(),
            'status' => 'open',
            'opening_balance' => $data['opening_balance'] ?? 0,
        ]);

        return back()->with('success', ['key' => 'flash.fiscal_year_created', 'params' => ['year' => FiscalPeriod::label($data['year'])]]);
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

        return back()->with('success', 'flash.fiscal_year_updated');
    }

    public function close(FiscalYear $fiscalYear, Request $request): RedirectResponse
    {
        // closeYear never rewrites a closed next season, so say so instead of
        // claiming the balance was carried forward.
        $nextAlreadyClosed = FiscalYear::query()
            ->where('year', $fiscalYear->year + 1)->where('status', 'closed')->exists();

        $this->finance->closeYear($fiscalYear, $request->user());

        return back()->with('success', [
            'key' => $nextAlreadyClosed ? 'flash.fiscal_year_closed_no_carry' : 'flash.fiscal_year_closed',
            'params' => ['year' => FiscalPeriod::label($fiscalYear->year), 'next' => FiscalPeriod::label($fiscalYear->year + 1)],
        ]);
    }

    public function reopen(FiscalYear $fiscalYear): RedirectResponse
    {
        $this->finance->reopenYear($fiscalYear);

        return back()->with('success', ['key' => 'flash.fiscal_year_reopened', 'params' => ['year' => FiscalPeriod::label($fiscalYear->year)]]);
    }

    /**
     * Remove an empty year (typically one created by mistake). Only an open
     * year with no active transaction qualifies; its archived transactions are
     * purged with it and its budget lines go by FK cascade.
     */
    public function destroy(FiscalYear $fiscalYear): RedirectResponse
    {
        if (! $fiscalYear->isDeletable()) {
            return back()->with('error', ['key' => 'flash.fiscal_year_not_empty', 'params' => ['year' => FiscalPeriod::label($fiscalYear->year)]]);
        }

        DB::transaction(function () use ($fiscalYear) {
            // Archived rows are already out of every total, so a query delete
            // (no observer) changes no balance or debt.
            $fiscalYear->ownTransactions()->where('archived', true)->delete();
            $fiscalYear->delete();
        });

        return back()->with('success', ['key' => 'flash.fiscal_year_deleted', 'params' => ['year' => FiscalPeriod::label($fiscalYear->year)]]);
    }
}
