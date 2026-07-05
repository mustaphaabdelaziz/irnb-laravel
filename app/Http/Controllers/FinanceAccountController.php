<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceAccountController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function store(Request $request): RedirectResponse
    {
        FinanceAccount::create($this->validateData($request));
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'Account added.');
    }

    public function update(Request $request, FinanceAccount $financeAccount): RedirectResponse
    {
        $financeAccount->update($this->validateData($request));
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'Account updated.');
    }

    public function destroy(FinanceAccount $financeAccount): RedirectResponse
    {
        if ($financeAccount->transactions()->exists()) {
            return back()->with('error', 'Cannot delete an account that has transactions. Deactivate it instead.');
        }

        $financeAccount->delete();

        return back()->with('success', 'Account deleted.');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['cash', 'bank', 'other'])],
            'account_number' => ['nullable', 'string', 'max:120'],
            'opening_balance' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
