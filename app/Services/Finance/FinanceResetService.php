<?php

namespace App\Services\Finance;

use App\Services\Storage\PrivateFileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Wipes every financial RECORD so the club can start its books fresh, while
 * keeping the finance SETUP (registers, categories, fiscal years) in place.
 *
 * Deleted: subscriptions (+ branch/category links), player obligations,
 * transactions (+ receipt files), transfers and budgets.
 * Zeroed:  player debts, register balances, fiscal-year figures (years reopen).
 *
 * Irreversible, so backup() must run first; it copies the SQLite database and
 * the receipts folder aside.
 */
class FinanceResetService
{
    public const RECEIPTS_DIR = 'receipts';

    /** What a reset would remove, for the confirmation prompt. @return array<string, int> */
    public function counts(): array
    {
        return [
            'subscriptions' => DB::table('subscriptions')->count(),
            'player_subscriptions' => DB::table('player_subscriptions')->count(),
            'transactions' => DB::table('transactions')->count(),
            'finance_transfers' => DB::table('finance_transfers')->count(),
            'budgets' => DB::table('budgets')->count(),
        ];
    }

    /**
     * Copies the database and the receipt files to
     * storage/app/private/finance-reset/<timestamp>/ and returns that folder.
     * Returns null when the database is not SQLite (back it up by other means).
     */
    public function backup(): ?string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return null;
        }

        $dir = Storage::disk(PrivateFileStorage::DISK)->path('finance-reset/'.now()->format('Y-m-d_His'));
        File::ensureDirectoryExists($dir);

        $target = $dir.DIRECTORY_SEPARATOR.'database.sqlite';
        // VACUUM INTO writes a consistent copy even while the app holds the file open.
        DB::statement('VACUUM INTO ?', [$target]);

        if (! is_file($target)) {
            throw new RuntimeException("The database backup could not be written to {$target}.");
        }

        $receipts = Storage::disk(PrivateFileStorage::DISK)->path(self::RECEIPTS_DIR);
        if (is_dir($receipts)) {
            File::copyDirectory($receipts, $dir.DIRECTORY_SEPARATOR.self::RECEIPTS_DIR);
        }

        return $dir;
    }

    /** Deletes the financial records atomically. @return array<string, int> what was removed */
    public function reset(): array
    {
        $counts = $this->counts();

        DB::transaction(function () {
            // Explicit nulling/ordering rather than relying on ON DELETE rules,
            // which SQLite only enforces when foreign keys are switched on.
            DB::table('equipment_items')->whereNotNull('purchase_transaction_id')
                ->update(['purchase_transaction_id' => null]);

            DB::table('player_subscriptions')->delete();
            DB::table('transactions')->delete();
            DB::table('branch_subscription')->delete();
            DB::table('category_subscription')->delete();
            DB::table('subscriptions')->delete();
            DB::table('finance_transfers')->delete();
            DB::table('budgets')->delete();

            DB::table('players')->update(['outstanding_debt' => 0]);
            DB::table('finance_accounts')->update(['opening_balance' => 0, 'current_balance' => 0]);
            DB::table('fiscal_years')->update([
                'status' => 'open',
                'opening_balance' => 0,
                'closing_balance' => null,
                'total_income' => null,
                'total_expense' => null,
                'closed_at' => null,
                'closed_by_user_id' => null,
            ]);
        });

        // Files last: if the transaction failed, the receipts are still referenced.
        Storage::disk(PrivateFileStorage::DISK)->deleteDirectory(self::RECEIPTS_DIR);

        return $counts;
    }
}
