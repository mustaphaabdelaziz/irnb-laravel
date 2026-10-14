<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\FinanceService;
use Closure;
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

        $accounts = FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->withCount(['transactions', 'incomingTransfers', 'outgoingTransfers'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $branches = Branch::query()->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']);

        $accounts->each(function (FinanceAccount $account) {
            // One number for the page: a caisse that took part in a move can't be deleted.
            $account->transfers_count = $account->incoming_transfers_count + $account->outgoing_transfers_count;
            unset($account->incoming_transfers_count, $account->outgoing_transfers_count);
        });

        $coffres = $branches->map(function (Branch $branch) use ($accounts) {
            $coffre = $accounts->first(fn (FinanceAccount $a) => $a->is_treasury && $a->branch_id === $branch->id);
            $caisses = $coffre ? $accounts->where('parent_account_id', $coffre->id)->values() : collect();

            return [
                'branch' => $branch,
                'coffre' => $coffre,
                // The coffre's balance is a roll-up; this is the cash held in it directly.
                'own_balance' => $coffre ? $this->finance->ownBalance($coffre) : 0.0,
                'caisses' => $caisses,
            ];
        })->values();

        $clubLevel = $accounts
            ->filter(fn (FinanceAccount $a) => ! $a->branch_id && ! $a->parent_account_id && ! $a->is_opening_fund)
            ->values();
        $openingFund = $accounts->first(fn (FinanceAccount $a) => $a->is_opening_fund && (float) $a->current_balance != 0.0);

        return Inertia::render('Finance/Registers', [
            'coffres' => $coffres,
            'clubLevel' => $clubLevel,
            'openingFund' => $openingFund,
            'totals' => [
                'cash' => round(
                    $coffres->sum(fn ($g) => (float) ($g['coffre']?->current_balance ?? 0))
                    + $clubLevel->sum(fn ($a) => (float) $a->current_balance)
                    + (float) ($openingFund?->current_balance ?? 0),
                    2,
                ),
                'active' => $accounts->where('is_active', true)->where('is_opening_fund', false)->count(),
            ],
            'branches' => $branches,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
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
                function (string $attribute, mixed $value, Closure $fail) {
                    if (FinanceAccount::whereKey($value)->where('is_opening_fund', true)->exists()) {
                        $fail(__('Money cannot be moved into the opening fund.'));
                    }
                },
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

            // A coffre only gives the cash it holds itself, not its caisses' money.
            if ($this->finance->ownBalance($source) < round((float) $validated['amount'], 2)) {
                throw ValidationException::withMessages([
                    'amount' => __('The source cash register does not have enough money for this transfer.'),
                ]);
            }

            $transfer = FinanceTransfer::create([
                ...$validated,
                'created_by_user_id' => $request->user()?->id,
            ]);

            ActivityRecorder::record($request->user(), ActivityAction::TRANSFER_RECORDED, $transfer, [
                'amount' => (float) $transfer->amount,
            ]);

            $this->finance->recomputeAccountBalances();
        });

        return back()->with('success', 'flash.transfer_created');
    }
}
