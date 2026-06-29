<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Models\Player;
use App\Models\Transaction;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return Inertia::render('Transactions/Create', [
            'players' => Player::where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname']),
        ]);
    }

    public function store(StoreTransactionRequest $request, FileStorageService $files): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['receipt']);
        $validated['recorded_by_user_id'] = $request->user()?->id;

        if (empty($validated['fiscal_year'])) {
            $validated['fiscal_year'] = now()->year;
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
            'players' => Player::where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname']),
        ]);
    }

    public function update(StoreTransactionRequest $request, Transaction $transaction, FileStorageService $files): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['receipt']);

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
        $transaction->update(['archived' => true]);

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction archived successfully.');
    }
}
