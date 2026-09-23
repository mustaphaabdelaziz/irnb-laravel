<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $nextOrder = (int) DB::table('finance_accounts')->max('sort_order') + 1;

        // Ensure every branch has category registers for every category
        foreach (DB::table('branches')->orderBy('id')->get() as $branch) {
            foreach (DB::table('categories')->orderBy('id')->get() as $category) {
                DB::table('finance_accounts')->insertOrIgnore([
                    'branch_id' => $branch->id,
                    'category_id' => $category->id,
                    'parent_account_id' => DB::table('finance_accounts')
                        ->where('branch_id', $branch->id)
                        ->where('is_treasury', true)
                        ->value('id'),
                    'name' => $category->name.' Cash Register',
                    'type' => 'cash',
                    'is_treasury' => false,
                    'currency' => 'DZD',
                    'is_active' => true,
                    'sort_order' => $nextOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Ensure club-wide bank account exists
        DB::table('finance_accounts')->insertOrIgnore([
            'name' => 'Club Bank Account',
            'type' => 'bank',
            'branch_id' => null,
            'category_id' => null,
            'parent_account_id' => null,
            'is_treasury' => false,
            'opening_balance' => 0,
            'current_balance' => 0,
            'currency' => 'DZD',
            'is_active' => true,
            'sort_order' => $nextOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Remove club bank account if it has no transactions
        DB::table('finance_accounts')
            ->where('name', 'Club Bank Account')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.finance_account_id', 'finance_accounts.id');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('finance_transfers')
                    ->whereColumn('finance_transfers.from_account_id', 'finance_accounts.id')
                    ->orWhereColumn('finance_transfers.to_account_id', 'finance_accounts.id');
            })
            ->delete();

        // Remove category registers created by this migration (those with no transactions)
        DB::table('finance_accounts')
            ->whereNotNull('category_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.finance_account_id', 'finance_accounts.id');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('finance_transfers')
                    ->whereColumn('finance_transfers.from_account_id', 'finance_accounts.id')
                    ->orWhereColumn('finance_transfers.to_account_id', 'finance_accounts.id');
            })
            ->delete();
    }
};
