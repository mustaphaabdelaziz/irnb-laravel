<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Services\Finance\CashRegisterProvisioner;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        FinanceAccount::create($this->prepareHierarchy($data));
        $this->finance->recomputeAccountBalances();

        return back()->with('success', 'flash.account_added');
    }

    public function update(Request $request, FinanceAccount $financeAccount): RedirectResponse
    {
        $structural = $financeAccount->is_opening_fund || $financeAccount->is_treasury;

        // A coffre and the Fonds Initial never link to a category: drop it
        // before validation so a stray value cannot trip the club-level check.
        if ($structural) {
            $request->merge(['category_id' => null]);
        }

        // Leaving branch_id out means "unchanged"; only an explicit empty value
        // moves the caisse to club level.
        if (! $structural && ! $request->has('branch_id')) {
            $request->merge(['branch_id' => $financeAccount->branch_id]);
        }

        $data = $this->validateData($request, $financeAccount);

        // The Fonds Initial and a coffre are structural: renamable, opening
        // balance editable, but never moved, linked or switched off.
        if ($structural) {
            unset($data['branch_id'], $data['category_id']);
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
        if ($financeAccount->is_opening_fund) {
            return back()->with('error', 'flash.opening_fund_required');
        }

        if ($financeAccount->is_treasury && $financeAccount->childAccounts()->exists()) {
            return back()->with('error', 'flash.coffre_has_caisses');
        }

        $used = $financeAccount->transactions()->exists()
            || $financeAccount->incomingTransfers()->exists()
            || $financeAccount->outgoingTransfers()->exists();

        if ($used) {
            return back()->with('error', $financeAccount->is_treasury ? 'flash.coffre_cannot_be_deleted' : 'flash.caisse_used_deactivate');
        }

        $financeAccount->delete();

        return back()->with('success', 'flash.account_deleted');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request, ?FinanceAccount $account = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['cash', 'bank', 'other'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // A member category this caisse collects for by default — only inside a coffre.
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'account_number' => ['nullable', 'string', 'max:120'],
            'opening_balance' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Only a caisse inside a coffre collects for a category. Leaving the
        // field out on an update keeps the existing link (it is judged below
        // against the branch the caisse ends up in).
        if (! empty($data['category_id']) && empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'category_id' => __('Only a caisse inside a safe can be linked to a category.'),
            ]);
        }

        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $account?->category_id;

        if (! empty($categoryId) && ! empty($data['branch_id'])) {
            $taken = FinanceAccount::where('branch_id', $data['branch_id'])
                ->where('category_id', $categoryId)
                ->when($account, fn ($q) => $q->whereKeyNot($account->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'category_id' => __('This category already has a caisse in this branch.'),
                ]);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function prepareHierarchy(array $data): array
    {
        $data['is_treasury'] = false;

        if (empty($data['branch_id'])) {
            $data['parent_account_id'] = null;
            $data['category_id'] = null;

            return $data;
        }

        $branch = Branch::findOrFail($data['branch_id']);
        $data['parent_account_id'] = ($branch->treasury()->first()
            ?? app(CashRegisterProvisioner::class)->forBranch($branch))->id;

        return $data;
    }
}
