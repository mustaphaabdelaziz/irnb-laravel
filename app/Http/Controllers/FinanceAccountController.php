<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Services\Finance\CashRegisterProvisioner;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceAccountController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        // Only one club-wide bank account. (Branch treasuries need no such check:
        // they are provisioned, never created here — prepareHierarchy() forces
        // is_treasury off for every account attached to a branch.)
        if ($data['type'] === 'bank' && empty($data['branch_id'])
            && FinanceAccount::where('type', 'bank')->whereNull('branch_id')->exists()) {
            return back()->with('error', 'flash.club_bank_account_already_exists');
        }

        $data = $this->prepareHierarchy($data);
        FinanceAccount::create($data);
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'flash.account_added');
    }

    public function update(Request $request, FinanceAccount $financeAccount): RedirectResponse
    {
        $data = $this->validateData($request);

        // The branch treasury, opening fund, and automatic category-register identity are
        // structural. They can be renamed or given an opening balance, but not
        // detached from their branch/category or disabled.
        if ($financeAccount->is_treasury || $financeAccount->category_id || $financeAccount->is_opening_fund) {
            unset($data['branch_id']);
            $data['is_active'] = true;
        } else {
            $data = $this->prepareHierarchy($data);
        }

        $financeAccount->update($data);
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'flash.account_updated');
    }

    public function destroy(FinanceAccount $financeAccount): RedirectResponse
    {
        // Opening fund cannot be deleted (always system required)
        if ($financeAccount->is_opening_fund) {
            return back()->with('error', 'flash.system_register_required');
        }

        // Check if account has any transactions
        if ($financeAccount->transactions()->exists()) {
            return back()->with('error', 'flash.account_has_transactions');
        }

        // Check if account has any transfers
        if ($financeAccount->incomingTransfers()->exists() || $financeAccount->outgoingTransfers()->exists()) {
            return back()->with('error', 'flash.account_has_transfers');
        }

        // If it's a treasury, check if it has child accounts (category registers)
        if ($financeAccount->is_treasury && $financeAccount->childAccounts()->exists()) {
            return back()->with('error', 'flash.treasury_has_child_accounts');
        }

        $financeAccount->delete();

        return back()->with('success', 'flash.account_deleted');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['cash', 'bank', 'other'])],
            // No category_id: category registers are provisioned per branch, never entered.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'account_number' => ['nullable', 'string', 'max:120'],
            'opening_balance' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function prepareHierarchy(array $data): array
    {
        if (empty($data['branch_id'])) {
            $data['parent_account_id'] = null;

            return $data;
        }

        $branch = Branch::findOrFail($data['branch_id']);
        $treasury = $branch->treasury()->first()
            ?? app(CashRegisterProvisioner::class)->forBranch($branch);

        $data['parent_account_id'] = $treasury->id;
        $data['is_treasury'] = false;

        return $data;
    }
}
