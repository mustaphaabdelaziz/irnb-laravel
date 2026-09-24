<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BoardMeetingController;
use App\Http\Controllers\BoardMemberController;
use App\Http\Controllers\BoardRoleController;
use App\Http\Controllers\BoardTaskController;
use App\Http\Controllers\BoardTermController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\EquipmentCatalogController;
use App\Http\Controllers\EquipmentCategoryController;
use App\Http\Controllers\EquipmentItemController;
use App\Http\Controllers\EquipmentOutController;
use App\Http\Controllers\FinanceAccountController;
use App\Http\Controllers\FinanceCategoryController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MemberJobController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerDocumentController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerPrintController;
use App\Http\Controllers\PlayerStatusController;
use App\Http\Controllers\PlayerSubscriptionController;
use App\Http\Controllers\PlayerTransactionController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StorageLocationController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteConfigController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

Route::get('/', [PublicController::class, 'home'])->name('home');

// Serve public-disk files (logos, uploads) inside the desktop app, where the
// public/storage symlink that a normal web server would use does not exist.
// Uses /media (not /storage — that path is taken by the framework's local-disk
// "serve" route, which points at the private disk and would 404 these files).
Route::get('/media/{path}', function (string $path) {
    $decoded = str_replace('\\', '/', rawurldecode($path));

    // Reject NTFS alternate-data-stream / drive-letter syntax and raw control
    // characters up front, before any other check, on both the path as routed
    // and its decoded form. On Windows, "receipts::$INDEX_ALLOCATION/a.pdf"
    // (or "receipts:$I30:$INDEX_ALLOCATION/a.pdf") opens the receipts/
    // directory itself and resolves "a.pdf" inside it — confirmed with a
    // direct file_exists() probe — even though the segment string
    // "receipts::$INDEX_ALLOCATION" never equals "receipts", so a plain
    // equality check against the folder-name list below would miss it. No
    // legitimate stored path contains a colon or a control character.
    foreach ([$path, $decoded] as $candidate) {
        abort_if(str_contains($candidate, ':'), 404);
        abort_if((bool) preg_match('/[\x00-\x1F]/', $candidate), 404);
    }
    abort_if(str_contains($path, '..'), 404);

    // minutes/ and receipts/ now live only on the private disk (Tasks 12 and
    // 13), served solely by their authenticated routes. Refuse every spelling
    // of those folders here — case, doubled slashes, "./" segments, percent
    // encoding — so a file an old upload left behind in public/minutes/ or
    // public/receipts/ (unreferenced by any row, hence never moved by their
    // migration) can never be served through this public route.
    $normalised = ltrim($decoded, '/');
    $segments = array_values(array_filter(explode('/', $normalised), fn ($segment) => $segment !== '' && $segment !== '.'));
    // Defence in depth: cut at the first ':' before comparing, so even
    // without the guard above, "receipts::$INDEX_ALLOCATION" still reduces
    // to "receipts".
    $first = strtolower(explode(':', $segments[0] ?? '', 2)[0]);
    abort_if(in_array($first, ['minutes', 'receipts'], true), 404);

    // Windows 8.3 short filenames: NTFS also answers to an auto-generated
    // "~1"-suffixed alias of a long folder name (e.g. "RECEIP~1" for
    // "receipts"), which opens the same directory as the full name even
    // though the segment string never equals "receipts". Refuse any first
    // segment containing "~" outright — no legitimate stored folder name
    // uses one.
    abort_if(str_contains($first, '~'), 404);

    abort_unless(Storage::disk('public')->exists($path), 404);

    return response()->file(Storage::disk('public')->path($path));
})->where('path', '.*')->name('media.public');

// Language switch
Route::get('/lang/{locale}', [LanguageController::class, 'switch'])->name('lang.switch');

// Reachable by a logged-in but not-yet-approved member (outside the approval gate).
Route::middleware('auth')->get('/account/pending', fn () => Inertia::render('Auth/Pending'))
    ->name('account.pending');

// Authenticated routes — require a verified email AND an approved, active account.
// The `permission` middleware enforces per-module (view/add/edit/delete) access
// derived from each route name via config/permissions.php.
Route::middleware(['auth', 'verified', 'approved', 'permission'])->group(function () {

    // Dashboard
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Player bulk import + export + bulk actions (declared before the resource so the static paths win)
    Route::get('/players/import/template', [PlayerImportController::class, 'template'])->name('players.import.template');
    Route::post('/players/import', [PlayerImportController::class, 'store'])->name('players.import.store');
    Route::get('/players/export', [PlayerController::class, 'export'])->name('players.export');
    Route::post('/players/bulk-archive', [PlayerController::class, 'bulkArchive'])->name('players.bulkArchive');
    Route::post('/players/bulk-restore', [PlayerController::class, 'bulkRestore'])->name('players.bulkRestore');
    Route::post('/players/bulk-force-delete', [PlayerController::class, 'bulkForceDelete'])->name('players.bulkForceDelete');
    Route::post('/players/bulk-update', [PlayerController::class, 'bulkUpdate'])->name('players.bulkUpdate');
    Route::get('/players/labels', [PlayerPrintController::class, 'labels'])->name('players.labels');
    Route::get('/players/board-table', [PlayerPrintController::class, 'boardTable'])->name('players.board-table');

    // Players
    Route::resource('players', PlayerController::class);
    Route::put('/players/{player}/restore', [PlayerController::class, 'restore'])->name('players.restore');
    Route::delete('/players/{player}/force', [PlayerController::class, 'forceDelete'])->name('players.forceDelete');

    // Player transactions (nested)
    Route::post('/players/{player}/transactions', [PlayerTransactionController::class, 'store'])->name('players.transactions.store');
    Route::put('/players/{player}/transactions/{transaction}', [PlayerTransactionController::class, 'update'])->name('players.transactions.update');
    Route::delete('/players/{player}/transactions/{transaction}', [PlayerTransactionController::class, 'destroy'])->name('players.transactions.destroy');

    // Player subscription obligation lines (add manual/previous debt, edit amount/exempt, remove assignment)
    Route::post('/players/{player}/subscriptions', [PlayerSubscriptionController::class, 'store'])->name('players.subscriptions.store');
    Route::put('/players/{player}/subscriptions/{playerSubscription}', [PlayerSubscriptionController::class, 'update'])->name('players.subscriptions.update');
    Route::delete('/players/{player}/subscriptions/{playerSubscription}', [PlayerSubscriptionController::class, 'destroy'])->name('players.subscriptions.destroy');

    // Player documents — every name is players.documents.*, gated by the `documents`
    // module (config/permissions.php). Files are served from the private disk only.
    Route::post('/players/{player}/documents', [PlayerDocumentController::class, 'store'])->name('players.documents.store');
    Route::post('/players/{player}/documents/exempt', [PlayerDocumentController::class, 'exempt'])->name('players.documents.exempt');
    Route::put('/players/{player}/documents/{document}', [PlayerDocumentController::class, 'update'])->name('players.documents.update');
    Route::delete('/players/{player}/documents/{document}/exempt', [PlayerDocumentController::class, 'unexempt'])->name('players.documents.unexempt');
    Route::post('/players/{player}/documents/{document}/files', [PlayerDocumentController::class, 'storeFiles'])->name('players.documents.files.store');
    Route::get('/players/{player}/documents/files/{file}', [PlayerDocumentController::class, 'showFile'])->name('players.documents.files.show');
    Route::get('/players/{player}/documents/files/{file}/download', [PlayerDocumentController::class, 'downloadFile'])->name('players.documents.files.download');
    Route::delete('/players/{player}/documents/files/{file}', [PlayerDocumentController::class, 'destroyFile'])->name('players.documents.files.destroy');

    // Subscriptions
    Route::resource('subscriptions', SubscriptionController::class);
    Route::post('/subscriptions/{subscription}/assign', [SubscriptionController::class, 'assign'])->name('subscriptions.assign');
    Route::post('/subscriptions/{subscription}/assign-one', [SubscriptionController::class, 'assignOne'])->name('subscriptions.assignOne');
    Route::get('/subscriptions/{subscription}/export', [SubscriptionController::class, 'export'])->name('subscriptions.export');

    // Transactions (export/import declared before the resource so the static paths win)
    Route::get('/transactions/export', [TransactionController::class, 'export'])->name('transactions.export');
    Route::get('/transactions/import/template', [TransactionImportController::class, 'template'])->name('transactions.import.template');
    Route::post('/transactions/import', [TransactionImportController::class, 'store'])->name('transactions.import.store');
    Route::resource('transactions', TransactionController::class);
    Route::get('/transactions/{transaction}/receipt', [ReportController::class, 'transactionReceipt'])->name('transactions.receipt');
    // The uploaded receipt file — private: this (auth + transactions/view) is the only way to read it.
    Route::get('/transactions/{transaction}/receipt-file', [TransactionController::class, 'receiptFile'])->name('transactions.receipt-file.show');

    // Finance — year-grouped dashboard (view open to approved members)
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::get('/finance/registers', [CashRegisterController::class, 'index'])->name('finance.registers.index');
    Route::post('/finance/transfers', [CashRegisterController::class, 'transfer'])->name('finance.transfers.store');

    // PDF documents
    Route::get('/players/{player}/card', [ReportController::class, 'playerCard'])->name('players.card');
    Route::get('/players/{player}/label', [PlayerPrintController::class, 'label'])->name('players.label');
    Route::get('/reports/financial', [ReportController::class, 'financialSummary'])->name('reports.financial');

    // Equipment — catalog (equipment list) import/export declared before the resource so the static paths win
    Route::get('/equipment/catalogs/export', [EquipmentCatalogController::class, 'export'])->name('equipment.catalogs.export');
    Route::get('/equipment/catalogs/import/template', [EquipmentCatalogController::class, 'importTemplate'])->name('equipment.catalogs.import.template');
    Route::post('/equipment/catalogs/import', [EquipmentCatalogController::class, 'import'])->name('equipment.catalogs.import');
    Route::post('/equipment/catalogs/bulk-delete', [EquipmentCatalogController::class, 'bulkDestroy'])->name('equipment.catalogs.bulk-destroy');
    Route::resource('equipment/catalogs', EquipmentCatalogController::class)->names('equipment.catalogs');
    Route::post('/equipment/stock/receive', [EquipmentItemController::class, 'receive'])->name('equipment.stock.receive');
    Route::post('/equipment/items/{item}/split', [EquipmentItemController::class, 'split'])->name('equipment.stock.split');
    Route::post('/equipment/items', [EquipmentItemController::class, 'store'])->name('equipment.items.store');
    Route::get('/equipment/items/preview-serial', [EquipmentItemController::class, 'previewSerial'])->name('equipment.items.preview-serial');
    Route::get('/equipment/items/import/template', [EquipmentItemController::class, 'importTemplate'])->name('equipment.items.import.template');
    Route::get('/equipment/catalogs/{catalog}/items/export', [EquipmentItemController::class, 'export'])->name('equipment.items.export');
    Route::post('/equipment/catalogs/{catalog}/items/import', [EquipmentItemController::class, 'import'])->name('equipment.items.import');
    Route::post('/equipment/items/bulk-delete', [EquipmentItemController::class, 'bulkDestroy'])->name('equipment.items.bulk-destroy');
    Route::put('/equipment/items/{item}', [EquipmentItemController::class, 'update'])->name('equipment.items.update');
    Route::delete('/equipment/items/{item}', [EquipmentItemController::class, 'destroy'])->name('equipment.items.destroy');
    Route::post('/equipment/items/rent', [EquipmentItemController::class, 'rent'])->name('equipment.items.rent');
    Route::post('/equipment/rentals/{rental}/return', [EquipmentItemController::class, 'returnItem'])->name('equipment.rentals.return');
    Route::post('/equipment/items/{item}/repair', [EquipmentItemController::class, 'repair'])->name('equipment.items.repair');
    Route::post('/equipment/items/{item}/complete-repair', [EquipmentItemController::class, 'completeRepair'])->name('equipment.items.complete-repair');
    Route::post('/equipment/items/{item}/mark-lost', [EquipmentItemController::class, 'markLost'])->name('equipment.items.mark-lost');
    Route::post('/equipment/items/{item}/mark-found', [EquipmentItemController::class, 'markFound'])->name('equipment.items.mark-found');
    Route::get('/equipment/inventory', [EquipmentItemController::class, 'inventory'])->name('equipment.inventory');
    Route::get('/equipment/out', EquipmentOutController::class)->name('equipment.out');
    Route::get('/equipment/items/{item}/history', [EquipmentItemController::class, 'history'])->name('equipment.items.history');

    // Periodic inventory / stock-take sessions (count -> reconcile -> report)
    Route::get('/equipment/stocktake', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/equipment/stocktake', [InventoryController::class, 'store'])->name('inventory.store');
    Route::get('/equipment/stocktake/{session}/export', [InventoryController::class, 'export'])->name('inventory.export');
    Route::get('/equipment/stocktake/{session}/report', [ReportController::class, 'inventoryReport'])->name('inventory.report');
    Route::get('/equipment/stocktake/{session}', [InventoryController::class, 'show'])->name('inventory.show');
    Route::put('/equipment/stocktake/{session}/counts', [InventoryController::class, 'counts'])->name('inventory.counts');
    Route::post('/equipment/stocktake/{session}/complete', [InventoryController::class, 'complete'])->name('inventory.complete');
    Route::post('/equipment/stocktake/{session}/participants', [InventoryController::class, 'participants'])->name('inventory.participants');
    Route::delete('/equipment/stocktake/{session}', [InventoryController::class, 'destroy'])->name('inventory.destroy');

    // Management routes — each is governed by the `permission` middleware on the
    // parent group (module derived from the route name). No blanket admin gate.
    Route::group([], function () {
        // User / member management
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');
        Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');

        // Settings - lookup tables
        Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit']);
        Route::resource('branches', BranchController::class)->except(['show', 'create', 'edit']);
        Route::post('/branches/{branch}/players', [BranchController::class, 'syncPlayers'])->name('branches.players.sync');
        Route::resource('equipment-categories', EquipmentCategoryController::class)->except(['show', 'create', 'edit']);
        Route::resource('storage-locations', StorageLocationController::class)->except(['show', 'create', 'edit']);
        Route::post('/jobs/quick', [MemberJobController::class, 'quickStore'])->name('jobs.quick.store');
        Route::post('/jobs/{job}/merge', [MemberJobController::class, 'merge'])->name('jobs.merge');
        Route::resource('jobs', MemberJobController::class)->except(['show', 'create', 'edit']);
        Route::resource('positions', PositionController::class)->except(['show', 'create', 'edit']);
        Route::resource('player-statuses', PlayerStatusController::class)->except(['show', 'create', 'edit']);
        Route::resource('document-types', DocumentTypeController::class)->except(['show', 'create', 'edit']);

        // Finance management — fiscal years (close/reopen), budgets, chart of accounts, accounts
        Route::get('/finance/settings', [FinanceController::class, 'settings'])->name('finance.settings');
        Route::post('/finance/years', [FiscalYearController::class, 'store'])->name('finance.years.store');
        Route::put('/finance/years/{fiscalYear}', [FiscalYearController::class, 'update'])->name('finance.years.update');
        Route::post('/finance/years/{fiscalYear}/close', [FiscalYearController::class, 'close'])->name('finance.years.close');
        Route::post('/finance/years/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])->name('finance.years.reopen');
        Route::put('/finance/years/{fiscalYear}/budget', [BudgetController::class, 'update'])->name('finance.budget.update');
        Route::post('/finance/categories', [FinanceCategoryController::class, 'store'])->name('finance.categories.store');
        Route::put('/finance/categories/{financeCategory}', [FinanceCategoryController::class, 'update'])->name('finance.categories.update');
        Route::delete('/finance/categories/{financeCategory}', [FinanceCategoryController::class, 'destroy'])->name('finance.categories.destroy');
        Route::post('/finance/accounts', [FinanceAccountController::class, 'store'])->name('finance.accounts.store');
        Route::put('/finance/accounts/{financeAccount}', [FinanceAccountController::class, 'update'])->name('finance.accounts.update');
        Route::delete('/finance/accounts/{financeAccount}', [FinanceAccountController::class, 'destroy'])->name('finance.accounts.destroy');

        // Board of Directors — members, meetings, minutes, action tasks
        Route::get('/board', [BoardController::class, 'index'])->name('board.index');
        Route::get('/board/calendar', [BoardController::class, 'calendar'])->name('board.calendar');
        Route::get('/board/members', [BoardController::class, 'members'])->name('board.members');
        Route::get('/board/members/export', [BoardController::class, 'exportMembers'])->name('board.members.export');
        Route::post('/board/members', [BoardMemberController::class, 'store'])->name('board.members.store');
        Route::post('/board/members/{boardMember}', [BoardMemberController::class, 'update'])->name('board.members.update');
        Route::delete('/board/members/{boardMember}', [BoardMemberController::class, 'destroy'])->name('board.members.destroy');

        // Board terms (mandates)
        Route::post('/board/terms', [BoardTermController::class, 'store'])->name('board.terms.store');
        Route::put('/board/terms/{boardTerm}', [BoardTermController::class, 'update'])->name('board.terms.update');
        Route::delete('/board/terms/{boardTerm}', [BoardTermController::class, 'destroy'])->name('board.terms.destroy');

        // Board roles (managed lookup)
        Route::resource('board-roles', BoardRoleController::class)->except(['show', 'create', 'edit']);

        Route::get('/board/meetings', [BoardController::class, 'meetings'])->name('board.meetings');
        Route::get('/board/meetings/{meeting}', [BoardController::class, 'meeting'])->name('board.meetings.show');
        Route::get('/board/meetings/{meeting}/minutes', [ReportController::class, 'boardMinutes'])->name('board.meetings.minutes');
        Route::post('/board/meetings', [BoardMeetingController::class, 'store'])->name('board.meetings.store');
        Route::put('/board/meetings/{meeting}', [BoardMeetingController::class, 'update'])->name('board.meetings.update');
        Route::put('/board/meetings/{meeting}/attendance', [BoardMeetingController::class, 'attendance'])->name('board.meetings.attendance');
        Route::post('/board/meetings/{meeting}/attachment', [BoardMeetingController::class, 'attachment'])->name('board.meetings.attachment');
        // Minutes are private: this (auth + board/view) is the only way to read them.
        Route::get('/board/meetings/{meeting}/attachment', [BoardMeetingController::class, 'showAttachment'])->name('board.meetings.attachment.show');
        Route::delete('/board/meetings/{meeting}/attachment', [BoardMeetingController::class, 'deleteAttachment'])->name('board.meetings.attachment.delete');
        // Meetings are never deleted: cancelling keeps the record (who/when/why).
        Route::post('/board/meetings/{meeting}/cancel', [BoardMeetingController::class, 'cancel'])->name('board.meetings.cancel');

        Route::get('/board/tasks', [BoardController::class, 'tasks'])->name('board.tasks');
        Route::get('/board/tasks/export', [BoardController::class, 'exportTasks'])->name('board.tasks.export');
        Route::post('/board/tasks', [BoardTaskController::class, 'store'])->name('board.tasks.store');
        Route::put('/board/tasks/{boardTask}', [BoardTaskController::class, 'update'])->name('board.tasks.update');
        Route::delete('/board/tasks/{boardTask}', [BoardTaskController::class, 'destroy'])->name('board.tasks.destroy');

        // Website configuration
        Route::get('/settings', [WebsiteConfigController::class, 'show'])->name('settings.show');
        Route::put('/settings', [WebsiteConfigController::class, 'update'])->name('settings.update');
        // Lightweight, flash-free endpoint so picking a club color auto-saves instantly.
        Route::put('/settings/theme', [WebsiteConfigController::class, 'updateTheme'])->name('settings.theme');
    });

    // Role & access management — superadmin only (not module-mapped).
    Route::middleware('superadmin')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    // Database backup & restore — desktop app only, superadmin only.
    // Route names are intentionally unmapped in config/permissions.php: the
    // `permission` middleware passes unmapped names through, and `superadmin` gates them.
    Route::middleware(['desktop', 'superadmin'])->group(function () {
        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::post('/backups/tick', [BackupController::class, 'tick'])->name('backups.tick');
        Route::put('/backups/settings', [BackupController::class, 'updateSettings'])->name('backups.settings');
        Route::post('/backups/folder', [BackupController::class, 'chooseFolder'])->name('backups.folder');
        Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
        Route::post('/backups/reveal', [BackupController::class, 'reveal'])->name('backups.reveal');

        // Dismisses the persistent restore banner (the `lastRestore` page prop). MUST stay
        // above /backups/{name}: that route would otherwise match this URL first and try to
        // delete a backup called "last-restore".
        Route::delete('/backups/last-restore', [BackupController::class, 'dismissLastRestore'])
            ->name('backups.last-restore.dismiss');

        Route::delete('/backups/{name}', [BackupController::class, 'destroy'])->name('backups.destroy');
    });
});

require __DIR__.'/auth.php';
