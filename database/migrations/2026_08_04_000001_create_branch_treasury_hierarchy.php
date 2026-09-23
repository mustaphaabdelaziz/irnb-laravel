<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('categories')
                ->nullOnDelete();
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('category_id')
                ->constrained('finance_accounts')
                ->nullOnDelete();
            $table->boolean('is_treasury')->default(false)->after('type');

            // NULL category ids remain available for the treasury and any
            // legitimate non-category account. A real category may occur only
            // once inside a branch.
            $table->unique(['branch_id', 'category_id']);
            $table->index(['parent_account_id', 'is_active']);
            $table->index(['branch_id', 'is_treasury']);
        });

        $now = now();
        $categories = DB::table('categories')->orderBy('id')->get(['id', 'name']);
        $nextOrder = (int) DB::table('finance_accounts')->max('sort_order');

        foreach (DB::table('branches')->orderBy('id')->get(['id', 'name']) as $branch) {
            $accounts = DB::table('finance_accounts')
                ->where('branch_id', $branch->id)
                ->orderBy('id')
                ->get();

            $defaultName = $branch->name.' Cash Register';
            $treasury = $accounts->firstWhere('name', $defaultName) ?? $accounts->first();

            if ($treasury) {
                $treasuryName = $treasury->name === $defaultName
                    ? $branch->name.' Treasury'
                    : $treasury->name;

                DB::table('finance_accounts')->where('id', $treasury->id)->update([
                    'name' => $treasuryName,
                    'category_id' => null,
                    'parent_account_id' => null,
                    'is_treasury' => true,
                    'updated_at' => $now,
                ]);
                $treasuryId = $treasury->id;
            } else {
                $treasuryId = DB::table('finance_accounts')->insertGetId([
                    'branch_id' => $branch->id,
                    'category_id' => null,
                    'parent_account_id' => null,
                    'name' => $branch->name.' Treasury',
                    'type' => 'cash',
                    'is_treasury' => true,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'currency' => 'DZD',
                    'is_active' => true,
                    'sort_order' => ++$nextOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Any extra pre-existing branch account is part of that branch's
            // treasury rather than an unrelated top-level balance.
            DB::table('finance_accounts')
                ->where('branch_id', $branch->id)
                ->where('id', '!=', $treasuryId)
                ->update(['parent_account_id' => $treasuryId, 'is_treasury' => false, 'updated_at' => $now]);

            foreach ($categories as $category) {
                DB::table('finance_accounts')->insert([
                    'branch_id' => $branch->id,
                    'category_id' => $category->id,
                    'parent_account_id' => $treasuryId,
                    'name' => $category->name.' Cash Register',
                    'type' => 'cash',
                    'is_treasury' => false,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'currency' => 'DZD',
                    'is_active' => true,
                    'sort_order' => ++$nextOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Category registers introduced by this migration are safe to remove
        // only when unused. Preserve any with financial history as ordinary
        // accounts before removing the hierarchy columns.
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

        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'category_id']);
            $table->dropIndex(['parent_account_id', 'is_active']);
            $table->dropIndex(['branch_id', 'is_treasury']);
            $table->dropConstrainedForeignId('parent_account_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('is_treasury');
        });
    }
};
