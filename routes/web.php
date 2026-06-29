<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentCatalogController;
use App\Http\Controllers\EquipmentItemController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MemberJobController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerTransactionController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteConfigController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [PublicController::class, 'home'])->name('home');

// Language switch
Route::get('/lang/{locale}', [LanguageController::class, 'switch'])->name('lang.switch');

// Reachable by a logged-in but not-yet-approved member (outside the approval gate).
Route::middleware('auth')->get('/account/pending', fn () => Inertia::render('Auth/Pending'))
    ->name('account.pending');

// Authenticated routes — require a verified email AND an approved, active account.
Route::middleware(['auth', 'verified', 'approved'])->group(function () {

    // Dashboard
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Player bulk import (declared before the resource so the static paths win)
    Route::get('/players/import/template', [PlayerImportController::class, 'template'])->name('players.import.template');
    Route::post('/players/import', [PlayerImportController::class, 'store'])->name('players.import.store');

    // Players
    Route::resource('players', PlayerController::class);

    // Player transactions (nested)
    Route::post('/players/{player}/transactions', [PlayerTransactionController::class, 'store'])->name('players.transactions.store');
    Route::put('/players/{player}/transactions/{transaction}', [PlayerTransactionController::class, 'update'])->name('players.transactions.update');

    // Subscriptions
    Route::resource('subscriptions', SubscriptionController::class);
    Route::post('/subscriptions/{subscription}/assign', [SubscriptionController::class, 'assign'])->name('subscriptions.assign');
    Route::post('/subscriptions/{subscription}/assign-one', [SubscriptionController::class, 'assignOne'])->name('subscriptions.assignOne');
    Route::get('/subscriptions/{subscription}/export', [SubscriptionController::class, 'export'])->name('subscriptions.export');

    // Transactions
    Route::resource('transactions', TransactionController::class);
    Route::get('/transactions/{transaction}/receipt', [ReportController::class, 'transactionReceipt'])->name('transactions.receipt');

    // PDF documents
    Route::get('/players/{player}/card', [ReportController::class, 'playerCard'])->name('players.card');
    Route::get('/reports/financial', [ReportController::class, 'financialSummary'])->name('reports.financial');

    // Equipment
    Route::resource('equipment/catalogs', EquipmentCatalogController::class)->names('equipment.catalogs');
    Route::post('/equipment/items', [EquipmentItemController::class, 'store'])->name('equipment.items.store');
    Route::post('/equipment/items/rent', [EquipmentItemController::class, 'rent'])->name('equipment.items.rent');
    Route::post('/equipment/rentals/{rental}/return', [EquipmentItemController::class, 'returnItem'])->name('equipment.rentals.return');
    Route::post('/equipment/items/{item}/repair', [EquipmentItemController::class, 'repair'])->name('equipment.items.repair');
    Route::post('/equipment/items/{item}/complete-repair', [EquipmentItemController::class, 'completeRepair'])->name('equipment.items.complete-repair');
    Route::post('/equipment/items/{item}/mark-lost', [EquipmentItemController::class, 'markLost'])->name('equipment.items.mark-lost');
    Route::get('/equipment/inventory', [EquipmentItemController::class, 'inventory'])->name('equipment.inventory');
    Route::get('/equipment/items/{item}/history', [EquipmentItemController::class, 'history'])->name('equipment.items.history');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        // User / member management
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');

        // Settings - lookup tables
        Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit']);
        Route::resource('jobs', MemberJobController::class)->except(['show', 'create', 'edit']);
        Route::resource('positions', PositionController::class)->except(['show', 'create', 'edit']);

        // Website configuration
        Route::get('/settings', [WebsiteConfigController::class, 'show'])->name('settings.show');
        Route::put('/settings', [WebsiteConfigController::class, 'update'])->name('settings.update');
        // Lightweight, flash-free endpoint so picking a club color auto-saves instantly.
        Route::put('/settings/theme', [WebsiteConfigController::class, 'updateTheme'])->name('settings.theme');
    });
});

require __DIR__.'/auth.php';
