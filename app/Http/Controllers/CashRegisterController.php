<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(): Response
    {
        $this->finance->recomputeAccountBalances();

        return Inertia::render('Finance/Registers', [
            'registers' => FinanceAccount::query()
                ->with([
                    'branch:id,name,name_ar,name_fr,name_en',
                    'category:id,name,name_ar,name_fr,name_en',
                ])
                ->withCount('transactions')
                ->where('is_opening_fund', false)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'openingFund' => FinanceAccount::query()
                ->where('is_opening_fund', true)
                ->first(),
            'branches' => Branch::query()->orderBy('name')->get([
                'id', 'name', 'name_ar', 'name_fr', 'name_en',
            ]),
            'transfers' => FinanceTransfer::query()
                ->with([
                    'fromAccount:id,name,branch_id,category_id,parent_account_id,is_treasury,is_opening_fund',
                    'fromAccount.branch:id,name,name_ar,name_fr,name_en',
                    'fromAccount.category:id,name,name_ar,name_fr,name_en',
                    'toAccount:id,name,branch_id,category_id,parent_account_id,is_treasury,is_opening_fund',
                    'toAccount.branch:id,name,name_ar,name_fr,name_en',
                    'toAccount.category:id,name,name_ar,name_fr,name_en',
                    'createdBy:id,name',
                ])
                ->latest('transfer_date')
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function transfer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_account_id' => [
                'required',
                Rule::exists('finance_accounts', 'id')->where('is_active', true),
                'different:to_account_id',
            ],
            'to_account_id' => [
                'required',
                Rule::exists('finance_accounts', 'id')->where('is_active', true),
                'different:from_account_id',
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $accountIds = [
                (int) $validated['from_account_id'],
                (int) $validated['to_account_id'],
            ];

            // Lock in stable id order to avoid two simultaneous opposite
            // transfers deadlocking each other.
            FinanceAccount::whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->finance->recomputeAccountBalances();
            $source = FinanceAccount::findOrFail($validated['from_account_id']);

            if ((float) $source->current_balance < (float) $validated['amount']) {
                throw ValidationException::withMessages([
                    'amount' => __('The source cash register does not have enough money for this transfer.'),
                ]);
            }

            FinanceTransfer::create([
                ...$validated,
                'created_by_user_id' => $request->user()?->id,
            ]);

            $this->finance->recomputeAccountBalances();
        });

        return back()->with('success', 'flash.transfer_created');
    }
}
