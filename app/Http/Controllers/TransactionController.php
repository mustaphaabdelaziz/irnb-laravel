<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Dashboard\ModuleStats;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\FinanceService;
use App\Services\Storage\FileStorageService;
use App\Services\Storage\PrivateFileStorage;
use App\Support\Export;
use App\Support\FinanceAccountLabel;
use App\Support\FiscalPeriod;
use App\Support\ListFilter;
use App\Support\TransactionTitle;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TransactionController extends Controller
{
    /** Stored payment-method code => the i18n key the screens use (resources/js/lib/statusLabels.js). */
    private const PAYMENT_METHOD_KEYS = ['bank' => 'bank_transfer'];

    /** Page sizes the list offers; anything else falls back to the first. */
    private const PER_PAGE = [25, 50, 100, 200];

    /**
     * Select filters (query parameter => column); each takes several values.
     * Every one has a control on the page (status: a removable chip). The old
     * `category` slug filter is gone — it had no control and no caller.
     */
    private const LIST_FILTER_COLUMNS = [
        'type' => 'transaction_type',
        'status' => 'status',
        'finance_category_id' => 'finance_category_id',
        'finance_account_id' => 'finance_account_id',
    ];

    /** Which values each select filter can apply (ListFilter specs). */
    private const LIST_FILTER_SPECS = [
        'type' => ['income', 'expense'],
        'status' => ['Paid', 'Partial', 'Unpaid', 'Exempt'],
        'finance_category_id' => ListFilter::IDS,
        'finance_account_id' => ListFilter::IDS,
        // Season keys or "all"; absent = the current season (see applyFilters).
        'fiscal_year' => 'ids|all',
    ];

    public function index(Request $request, ModuleStats $moduleStats): Response
    {
        $query = Transaction::query()->where('archived', false);
        $this->applyFilters($query, $request);

        $perPage = in_array((int) $request->input('per_page'), self::PER_PAGE, true)
            ? (int) $request->input('per_page')
            : self::PER_PAGE[0];

        $transactions = (clone $query)
            ->with(['recordedBy', 'receivedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => TransactionTitle::decorate($transaction));

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            // Everything below until `filters` follows the filters, so the page reloads it with the list.
            'strip' => $moduleStats->transactions($query),
            'incomeByCategory' => $this->categoryStats($request, 'income'),
            'expenseByCategory' => $this->categoryStats($request, 'expense'),
            'byAccount' => $this->accountStats($request),
            // Select filters come back as lists, whatever shape was sent.
            // per_page echoes the size applied, never a refused one.
            'filters' => [
                ...ListFilter::echo($request, self::LIST_FILTER_SPECS, ['search', 'date_from', 'date_to']),
                'per_page' => (string) $perPage,
            ],
            // Lookups as closures so filter reloads (partial) skip these queries.
            // Every category, inactive too: old entries stay filterable, and the
            // categories manager needs each one's usage.
            'financeCategories' => fn () => FinanceCategory::withCount('transactions')
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(),
            'financeAccounts' => fn () => FinanceAccount::query()
                ->where('is_opening_fund', false)
                ->with([
                    'branch:id,name,name_ar,name_fr,name_en',
                    'category:id,name,name_ar,name_fr,name_en',
                ])
                ->orderBy('sort_order')->orderBy('name')->get(),
            'seasons' => fn () => $this->seasonOptions(),
            'currentSeason' => fn () => FiscalPeriod::currentKey(),
        ]);
    }

    public function export(Request $request): SymfonyResponse
    {
        $query = Transaction::query()->with(['recordedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->where('archived', false);
        $this->applyFilters($query, $request);

        // Same order as the list.
        $rows = $query->orderByDesc('transaction_date')->orderByDesc('id')->get()->map(fn (Transaction $t) => [
            $t->transaction_date,
            TransactionTitle::for($t),
            UiLang::get((string) $t->transaction_type, $t->transaction_type),
            // The category's own name, never the stored slug.
            $t->financeCategory?->localized_name ?? UiLang::get((string) $t->transaction_type),
            (float) $t->amount,
            $t->status ? UiLang::get(strtolower($t->status), $t->status) : null,
            $t->payment_method ? UiLang::get(self::PAYMENT_METHOD_KEYS[$t->payment_method] ?? $t->payment_method, $t->payment_method) : null,
            FinanceAccountLabel::for($t->financeAccount),
            $t->description,
            $t->recordedBy?->name,
        ])->all();

        $headers = array_map(fn (string $key) => UiLang::get($key), [
            'col.date', 'col.title', 'col.type', 'col.category', 'col.amount', 'col.status',
            'col.payment_method', 'col.cash_register', 'col.description', 'col.recorded_by',
        ]);

        return Export::download(Export::format($request), 'transactions-'.now()->format('Y-m-d'), $headers, $rows, UiLang::get('transactions', 'Transactions'));
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

    public function store(StoreTransactionRequest $request, PrivateFileStorage $receipts): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['receipt']);
        $validated['recorded_by_user_id'] = $request->user()?->id;

        $financeCategory = FinanceCategory::find($validated['finance_category_id']);
        $validated['category'] = Str::slug($financeCategory->name, '_');

        if ($request->hasFile('receipt')) {
            // Receipts are private: only receiptFile() serves them, behind login.
            $stored = $receipts->store($request->file('receipt'), 'receipts');
            $validated['receipt_url'] = null;
            $validated['receipt_filename'] = $stored['path'];
        }

        try {
            $transaction = DB::transaction(function () use ($validated, $request) {
                $transaction = Transaction::create($validated);

                ActivityRecorder::record($request->user(), ActivityAction::TRANSACTION_RECORDED, $transaction, [
                    'amount' => (float) $transaction->amount,
                    'type' => $transaction->transaction_type,
                ]);

                return $transaction;
            });
        } catch (Throwable $e) {
            // Rejected (e.g. closed season): do not leave the upload orphaned.
            $receipts->delete($validated['receipt_filename'] ?? null);

            throw $e;
        }

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

    public function update(StoreTransactionRequest $request, Transaction $transaction, PrivateFileStorage $receipts, FileStorageService $files): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['receipt']);

        $financeCategory = FinanceCategory::find($validated['finance_category_id']);
        $validated['category'] = Str::slug($financeCategory->name, '_');

        $previousFile = null;

        if ($request->hasFile('receipt')) {
            $previousFile = $transaction->receipt_filename;

            $stored = $receipts->store($request->file('receipt'), 'receipts');
            $validated['receipt_url'] = null;
            $validated['receipt_filename'] = $stored['path'];
        }

        try {
            $transaction->update($validated);
        } catch (Throwable $e) {
            // Rejected (e.g. closed season): keep the old file, drop the new upload.
            $receipts->delete($validated['receipt_filename'] ?? null);

            throw $e;
        }

        if ($previousFile) {
            // The previous file, wherever it lives: private, or public for a row the
            // receipts migration could not move.
            $receipts->delete($previousFile);
            $files->delete($previousFile);
        }

        return redirect()->route('transactions.show', $transaction)
            ->with('success', 'flash.transaction_updated');
    }

    /** The uploaded receipt file (not the printed PDF receipt, which is transactions.receipt). */
    public function receiptFile(Transaction $transaction, PrivateFileStorage $receipts): StreamedResponse
    {
        $path = $transaction->receipt_filename;

        abort_unless($path && $receipts->exists($path), 404);

        return $receipts->serve($path, basename($path));
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        // Already archived: nothing changes, so no second cancellation is recorded.
        if (! $transaction->archived) {
            DB::transaction(function () use ($request, $transaction) {
                $transaction->update(['archived' => true]);

                ActivityRecorder::record($request->user(), ActivityAction::TRANSACTION_CANCELLED, $transaction, [
                    'amount' => (float) $transaction->amount,
                ]);
            });
        }

        return redirect()->route('transactions.index')
            ->with('success', 'flash.transaction_archived');
    }

    /**
     * Archive several transactions at once, like destroy() does one. Rows in a
     * closed fiscal year are skipped (and counted) rather than failing the batch.
     */
    public function bulkDestroy(Request $request, FinanceService $finance, RecalculatePlayerDebtService $debts): RedirectResponse
    {
        $validated = $request->validate([
            // exists on the array itself: one whereIn query, not one per id.
            'ids' => ['required', 'array', 'min:1', 'max:500', 'exists:transactions,id'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $transactions = Transaction::whereIn('id', $validated['ids'])->where('archived', false)->get();
        $closedYears = FiscalYear::where('status', 'closed')
            ->whereIn('year', $transactions->pluck('fiscal_year')->filter()->unique())
            ->pluck('year')
            ->all();
        [$skipped, $archivable] = $transactions->partition(fn (Transaction $tx) => in_array((int) $tx->fiscal_year, $closedYears, true));

        DB::transaction(function () use ($archivable, $finance, $debts, $request) {
            // One query instead of one observer round per row: the observer would
            // recompute every account balance for each transaction. The same
            // recomputations run once below instead.
            Transaction::whereIn('id', $archivable->modelKeys())->update(['archived' => true]);

            foreach ($archivable as $transaction) {
                ActivityRecorder::record($request->user(), ActivityAction::TRANSACTION_CANCELLED, $transaction, [
                    'amount' => (float) $transaction->amount,
                ]);
            }

            $finance->recomputeAccountBalances();
            FiscalYear::whereIn('id', $archivable->pluck('fiscal_year_id')->filter()->unique())
                ->get()
                ->each(fn (FiscalYear $year) => $finance->recomputeYear($year));
            PlayerSubscription::whereIn('id', $archivable->pluck('player_subscription_id')->filter()->unique())
                ->get()
                ->each(fn (PlayerSubscription $sub) => $debts->forSubscription($sub));
        });

        $params = ['count' => $archivable->count(), 'skipped' => $skipped->count()];

        return back()->with(
            $archivable->isEmpty() ? 'error' : 'success',
            ['key' => $skipped->isEmpty() ? 'flash.transactions_archived' : 'flash.transactions_archived_some_skipped', 'params' => $params],
        );
    }

    /**
     * The list filters, shared by the index, its statistics and its export so
     * the file always matches what the page shows.
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
                // The category by its name in any language — never the stored
                // slug, a transliteration for Arabic names.
                ->orWhereIn('finance_category_id', FinanceCategory::query()
                    ->where(fn (Builder $c) => $c->where('name', 'like', $like)
                        ->orWhere('name_ar', 'like', $like)
                        ->orWhere('name_fr', 'like', $like)
                        ->orWhere('name_en', 'like', $like))
                    ->select('id'))
                // A player's payments, found by any part of the name or the membership ID.
                ->orWhere(fn (Builder $p) => $p
                    ->where('related_entity_type', 'Player')
                    ->whereIn('related_entity_id', Player::query()->search($search)->select('id'))));
        }

        // Each may hold several values (key[]=a&key[]=b) or one scalar from
        // an old link: OR within a filter, AND across filters.
        foreach (self::LIST_FILTER_COLUMNS as $param => $column) {
            $values = ListFilter::get($request, $param, self::LIST_FILTER_SPECS[$param]);

            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        $from = $this->dateParam($request, 'date_from');
        $to = $this->dateParam($request, 'date_to');
        if ($from) {
            $query->whereDate('transaction_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        // Season: absent = the current one, unless a date range already says
        // which days to show; "all" = every season.
        $seasons = ListFilter::get($request, 'fiscal_year', self::LIST_FILTER_SPECS['fiscal_year']);
        if ($seasons === []) {
            if (! $from && ! $to) {
                $query->where('fiscal_year', FiscalPeriod::currentKey());
            }
        } elseif (! in_array('all', $seasons, true)) {
            $query->whereIn('fiscal_year', array_map('intval', $seasons));
        }
    }

    /** A real Y-m-d date from the query string, or null for anything else (a list, "2026-02-31"). */
    private function dateParam(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * The list's population for one statistics card minus the filter that card
     * drives, so clicking a bar never empties its own card (as on Players).
     *
     * @return Builder<Transaction>
     */
    private function statsQuery(Request $request, string $except): Builder
    {
        $query = Transaction::query()->where('archived', false);
        $this->applyFilters($query, new Request(Arr::except($request->query(), [$except])));

        return $query;
    }

    /**
     * One side of the books by finance category, as amounts, largest first.
     *
     * @return list<array{key: int|null, label: string|null, count: float}>
     */
    private function categoryStats(Request $request, string $type): array
    {
        $rows = $this->statsQuery($request, 'finance_category_id')
            ->where('transaction_type', $type)
            ->toBase()
            ->groupBy('finance_category_id')
            ->selectRaw('finance_category_id, SUM(amount) as total')
            ->orderByDesc('total')
            ->get();
        $names = FinanceCategory::whereIn('id', $rows->pluck('finance_category_id')->filter())->get()->keyBy('id');

        return $rows->map(fn (object $row): array => [
            'key' => $row->finance_category_id ? (int) $row->finance_category_id : null,
            'label' => $names->get($row->finance_category_id)?->localized_name,
            'count' => round((float) $row->total, 2),
        ])->values()->all();
    }

    /**
     * Entries per caisse, most used first, labelled like the Caisses page.
     * The opening fund is left out, as in the caisse filter. A row with no
     * (or a vanished) caisse has a null label, like an uncategorised row in
     * the category cards, so the page names both with one fallback word.
     *
     * @return list<array{key: int|null, label: string|null, count: int}>
     */
    private function accountStats(Request $request): array
    {
        $rows = $this->statsQuery($request, 'finance_account_id')
            ->toBase()
            ->groupBy('finance_account_id')
            ->selectRaw('finance_account_id, COUNT(*) as total')
            ->orderByDesc('total')
            ->get();
        $accounts = FinanceAccount::query()
            ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
            ->whereIn('id', $rows->pluck('finance_account_id')->filter())
            ->get()->keyBy('id');

        return $rows
            ->reject(fn (object $row): bool => (bool) $accounts->get($row->finance_account_id)?->is_opening_fund)
            ->map(fn (object $row): array => [
                'key' => $row->finance_account_id ? (int) $row->finance_account_id : null,
                'label' => FinanceAccountLabel::for($accounts->get($row->finance_account_id)) ?: null,
                'count' => (int) $row->total,
            ])->values()->all();
    }

    /**
     * Seasons the filter offers: every fiscal-year row plus the current
     * season, newest first, labelled "2026/2027".
     *
     * @return list<array{value: string, label: string}>
     */
    private function seasonOptions(): array
    {
        return FiscalYear::query()->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->push(FiscalPeriod::currentKey())
            ->unique()->sortDesc()->values()
            ->map(fn (int $key) => ['value' => (string) $key, 'label' => FiscalPeriod::label($key)])
            ->all();
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
            // Active categories, plus the entry's own one if it was deactivated since.
            'financeCategories' => FinanceCategory::query()
                ->where(fn (Builder $q) => $q->where('is_active', true)
                    ->when($transaction?->finance_category_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
            'clubCcp' => WebsiteConfig::query()->first()?->banking_info['ccp'] ?? null,
            'players' => Player::query()
                ->where(fn (Builder $q) => $q->where('archived', false)
                    ->when($ownPlayerId, fn (Builder $q) => $q->orWhere('id', $ownPlayerId)))
                ->with(['branches:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
                ->orderBy('lastname')->orderBy('firstname')
                ->get([
                    'id', ...Player::NAME_COLUMNS,
                    'membership_id', 'category_id', 'birthdate', 'picture_url', 'picture_filename',
                    'outstanding_debt', 'archived',
                ])
                ->map(fn (Player $player) => [
                    'id' => $player->id,
                    'name' => $player->fullname,
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
            // Where a new entry starts: the server's own default for an entry
            // without a player (DefaultRegisterResolver), else the first caisse
            // listed. The pickers never list the Fonds Initial.
            'defaultFinanceAccountId' => $registers->for(null, null)?->id ?? $financeAccounts->first()?->id,
            // An entry booked on an account the pickers hide (the Fonds Initial,
            // an inactive caisse) keeps showing it — read-only — instead of a blank select.
            'lockedAccount' => $transaction?->finance_account_id && ! $financeAccounts->contains('id', $transaction->finance_account_id)
                ? FinanceAccount::query()
                    ->with(['branch:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
                    ->find($transaction->finance_account_id, ['id', 'branch_id', 'category_id', 'name', 'type', 'is_treasury', 'is_opening_fund'])
                : null,
        ];
    }
}
