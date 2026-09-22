<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\WebsiteConfig;
use App\Services\Export\ExcelExporter;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Storage\FileStorageService;
use App\Support\TransactionTitle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::query()->where('archived', false);
        $this->applyFilters($query, $request);

        $income = (float) (clone $query)->where('transaction_type', 'income')->sum('amount');
        $expense = (float) (clone $query)->where('transaction_type', 'expense')->sum('amount');

        $transactions = $query->with(['recordedBy', 'receivedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => TransactionTitle::decorate($transaction));

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $request->only(['search', 'type', 'category', 'finance_category_id', 'finance_account_id', 'fiscal_year', 'status', 'date_from', 'date_to']),
            // Lookups as closures so filter reloads (partial) skip these queries.
            'financeCategories' => fn () => FinanceCategory::where('is_active', true)
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
            'financeAccounts' => fn () => FinanceAccount::query()
                ->with([
                    'branch:id,name,name_ar,name_fr,name_en',
                    'category:id,name,name_ar,name_fr,name_en',
                ])
                ->orderBy('sort_order')->orderBy('name')->get(),
            'stats' => [
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
                'debts' => (float) Player::where('archived', false)->sum('outstanding_debt'),
            ],
        ]);
    }

    public function export(Request $request, ExcelExporter $exporter)
    {
        $query = Transaction::query()->with(['recordedBy', 'financeAccount', ...TransactionTitle::RELATIONS])->where('archived', false);
        $this->applyFilters($query, $request);

        $rows = $query->latest('transaction_date')->get()->map(fn (Transaction $t) => [
            $t->transaction_date?->format('Y-m-d'),
            TransactionTitle::for($t),
            ucfirst($t->transaction_type),
            $t->financeCategory?->localized_name ?? $t->category,
            (float) $t->amount,
            $t->status,
            $t->payment_method,
            $t->financeAccount?->name,
            $t->description,
            $t->recordedBy?->name,
        ])->all();

        $headers = ['Date', 'Title', 'Type', 'Category', 'Amount', 'Status', 'Payment', 'Cash Register', 'Description', 'Recorded By'];

        return $exporter->download('Transactions', $headers, $rows, 'transactions-'.now()->format('Y-m-d').'.csv');
    }

    public function show(Transaction $transaction): Response
    {
        // Eager-load the transaction relation too: PlayerSubscription appends
        // remaining_amount/payment_status accessors that read ->transaction (else N+1).
        $transaction->load([
            'recordedBy',
            'receivedBy',
            'financeCategory',
            ...TransactionTitle::RELATIONS,
            ...Transaction::FINANCE_ACCOUNT_LABEL,
            'playerSubscriptions.player',
            'playerSubscriptions.transaction',
        ]);

        TransactionTitle::decorate($transaction);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Transactions/Create', $this->formOptions());
    }

    public function store(StoreTransactionRequest $request, FileStorageService $files): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['receipt']);
        $validated['recorded_by_user_id'] = $request->user()?->id;

        $financeCategory = FinanceCategory::find($validated['finance_category_id']);
        $validated['category'] = Str::slug($financeCategory->name, '_');

        if (empty($validated['fiscal_year'])) {
            $validated['fiscal_year'] = now()->year;
        }

        if ($this->yearClosed((int) $validated['fiscal_year'])) {
            return back()->withInput()->with('error', ['key' => 'flash.year_closed_add', 'params' => ['year' => $validated['fiscal_year']]]);
        }

        if ($request->hasFile('receipt')) {
            $stored = $files->storeFile($request->file('receipt'), 'receipts');
            $validated['receipt_url'] = $stored['url'];
            $validated['receipt_filename'] = $stored['filename'];
        }

        $transaction = Transaction::create($validated);

        return redirect()->route('transactions.show', $transaction)
            ->with('success', 'flash.transaction_created');
    }

    public function edit(Transaction $transaction): Response
    {
        // The edit form pre-fills the title with the generated label when none is stored.
        TransactionTitle::decorate($transaction->load(TransactionTitle::RELATIONS));

        return Inertia::render('Transactions/Edit', [
            'transaction' => $transaction,
            ...$this->formOptions($transaction),
        ]);
    }

    public function update(StoreTransactionRequest $request, Transaction $transaction, FileStorageService $files): RedirectResponse
    {
        if ($this->yearClosed((int) $transaction->fiscal_year)) {
            return back()->with('error', ['key' => 'flash.year_closed_edit', 'params' => ['year' => $transaction->fiscal_year]]);
        }

        $validated = $request->validated();
        unset($validated['receipt']);

        $financeCategory = FinanceCategory::find($validated['finance_category_id']);
        $validated['category'] = Str::slug($financeCategory->name, '_');

        if ($request->hasFile('receipt')) {
            $files->delete($transaction->receipt_filename);
            $stored = $files->storeFile($request->file('receipt'), 'receipts');
            $validated['receipt_url'] = $stored['url'];
            $validated['receipt_filename'] = $stored['filename'];
        }

        $transaction->update($validated);

        return redirect()->route('transactions.show', $transaction)
            ->with('success', 'flash.transaction_updated');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        if ($this->yearClosed((int) $transaction->fiscal_year)) {
            return back()->with('error', ['key' => 'flash.year_closed_delete', 'params' => ['year' => $transaction->fiscal_year]]);
        }

        $transaction->update(['archived' => true]);

        return redirect()->route('transactions.index')
            ->with('success', 'flash.transaction_archived');
    }

    /**
     * The list filters, shared by the index and its export so the file always
     * matches what the page shows.
     *
     * @param  Builder<Transaction>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $like = "%{$search}%";
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like)
                // A player's payments, found by any part of the name or the membership ID.
                ->orWhere(fn (Builder $p) => $p
                    ->where('related_entity_type', 'Player')
                    ->whereIn('related_entity_id', Player::query()->search($search)->select('id'))));
        }

        $columns = [
            'type' => 'transaction_type',
            'category' => 'category',
            'fiscal_year' => 'fiscal_year',
            'status' => 'status',
            'finance_category_id' => 'finance_category_id',
            'finance_account_id' => 'finance_account_id',
        ];
        foreach ($columns as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, $request->input($param));
            }
        }

        if ($request->filled('date_from')) {
            $query->where('transaction_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('transaction_date', '<=', $request->input('date_to'));
        }
    }

    /** Whether the given fiscal year exists and is closed (locked). */
    private function yearClosed(?int $year): bool
    {
        if (! $year) {
            return false;
        }

        return FiscalYear::where('year', $year)->where('status', 'closed')->exists();
    }

    /**
     * Shared Create/Edit form props. Players carry enough to tell two people
     * with the same name apart (membership ID, category, birth year, photo);
     * the transaction's own player stays selectable on edit even once archived.
     */
    private function formOptions(?Transaction $transaction = null): array
    {
        $financeAccounts = FinanceAccount::selectable()->get();
        $registers = new DefaultRegisterResolver($financeAccounts);
        $ownPlayerId = $transaction?->related_entity_type === 'Player' ? $transaction->related_entity_id : null;

        return [
            'financeCategories' => FinanceCategory::where('is_active', true)
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
            'clubCcp' => WebsiteConfig::query()->first()?->banking_info['ccp'] ?? null,
            'players' => Player::query()
                ->where(fn (Builder $q) => $q->where('archived', false)
                    ->when($ownPlayerId, fn (Builder $q) => $q->orWhere('id', $ownPlayerId)))
                ->with(['branches:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
                ->orderBy('lastname')->orderBy('firstname')
                ->get()
                ->map(fn (Player $player) => [
                    'id' => $player->id,
                    'name' => $player->short_name,
                    'fullname' => $player->fullname,
                    'membership_id' => $player->membership_id,
                    'category' => $player->category?->localized_name,
                    'birth_year' => $player->birthdate?->year,
                    'picture_url' => $player->picture_url,
                    'branches' => $player->branches->map(fn ($branch) => $branch->localized_name)->values(),
                    'outstanding_debt' => (float) $player->outstanding_debt,
                    'default_finance_account_id' => $registers->forPlayer($player)?->id,
                ]),
            'financeAccounts' => $financeAccounts,
        ];
    }
}
