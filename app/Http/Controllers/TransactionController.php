<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\WebsiteConfig;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::query()
            ->with(['recordedBy', 'receivedBy'])
            ->where('archived', false);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('transaction_type', $request->input('type'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', $request->input('fiscal_year'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('transaction_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('transaction_date', '<=', $request->input('date_to'));
        }

        $transactions = $query->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $request->only(['search', 'type', 'category', 'fiscal_year', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function export(Request $request, \App\Services\Export\ExcelExporter $exporter)
    {
        $query = Transaction::query()->with(['recordedBy', 'financeCategory'])->where('archived', false);

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(fn ($q) => $q->where('description', 'like', "%{$s}%")->orWhere('category', 'like', "%{$s}%"));
        }
        foreach (['type' => 'transaction_type', 'category' => 'category', 'fiscal_year' => 'fiscal_year', 'status' => 'status'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($col, $request->input($param));
            }
        }
        if ($request->filled('date_from')) {
            $query->where('transaction_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('transaction_date', '<=', $request->input('date_to'));
        }

        $rows = $query->latest('transaction_date')->get()->map(fn (Transaction $t) => [
            $t->transaction_date?->format('Y-m-d'),
            ucfirst($t->transaction_type),
            $t->financeCategory?->name ?? $t->category,
            (float) $t->amount,
            $t->status,
            $t->payment_method,
            $t->description,
            $t->recordedBy?->name,
        ])->all();

        $headers = ['Date', 'Type', 'Category', 'Amount', 'Status', 'Payment', 'Description', 'Recorded By'];

        return $exporter->download('Transactions', $headers, $rows, 'transactions-'.now()->format('Y-m-d').'.xlsx');
    }

    public function show(Transaction $transaction): Response
    {
        // Eager-load the transaction relation too: PlayerSubscription appends
        // remaining_amount/payment_status accessors that read ->transaction (else N+1).
        $transaction->load(['recordedBy', 'receivedBy', 'playerSubscriptions.player', 'playerSubscriptions.transaction']);

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
            return back()->withInput()->with('error', "Fiscal year {$validated['fiscal_year']} is closed. Reopen it to add transactions.");
        }

        if ($request->hasFile('receipt')) {
            $stored = $files->storeFile($request->file('receipt'), 'receipts');
            $validated['receipt_url'] = $stored['url'];
            $validated['receipt_filename'] = $stored['filename'];
        }

        $transaction = Transaction::create($validated);

        return redirect()->route('transactions.show', $transaction)
            ->with('success', 'Transaction created successfully.');
    }

    public function edit(Transaction $transaction): Response
    {
        return Inertia::render('Transactions/Edit', [
            'transaction' => $transaction,
            ...$this->formOptions(),
        ]);
    }

    public function update(StoreTransactionRequest $request, Transaction $transaction, FileStorageService $files): RedirectResponse
    {
        if ($this->yearClosed((int) $transaction->fiscal_year)) {
            return back()->with('error', "This transaction is in closed fiscal year {$transaction->fiscal_year} and cannot be edited.");
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
            ->with('success', 'Transaction updated successfully.');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        if ($this->yearClosed((int) $transaction->fiscal_year)) {
            return back()->with('error', "This transaction is in closed fiscal year {$transaction->fiscal_year} and cannot be removed.");
        }

        $transaction->update(['archived' => true]);

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction archived successfully.');
    }

    /** Whether the given fiscal year exists and is closed (locked). */
    private function yearClosed(?int $year): bool
    {
        if (! $year) {
            return false;
        }

        return FiscalYear::where('year', $year)->where('status', 'closed')->exists();
    }

    /** Shared Create/Edit form props: active finance categories, the club's own CCP details, and active players. */
    private function formOptions(): array
    {
        return [
            'financeCategories' => FinanceCategory::where('is_active', true)
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'type', 'name', 'color']),
            'clubCcp' => WebsiteConfig::query()->first()?->banking_info['ccp'] ?? null,
            'players' => Player::where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname']),
        ];
    }
}

