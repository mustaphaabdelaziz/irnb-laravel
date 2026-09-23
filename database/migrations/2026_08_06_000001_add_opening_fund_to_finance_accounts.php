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
            $table->boolean('is_opening_fund')->default(false)->after('is_treasury');
        });

        // Create a global opening fund account if one doesn't exist
        $exists = DB::table('finance_accounts')
            ->where('name', 'Opening Fund')
            ->where('is_opening_fund', true)
            ->exists();

        if (! $exists) {
            DB::table('finance_accounts')->insert([
                'name' => 'Opening Fund',
                'type' => 'cash',
                'opening_balance' => 0,
                'current_balance' => 0,
                'currency' => 'DZD',
                'is_active' => true,
                'is_opening_fund' => true,
                'is_treasury' => false,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Delete the opening fund account if it exists and has no transactions/transfers
        DB::table('finance_accounts')
            ->where('name', 'Opening Fund')
            ->where('is_opening_fund', true)
            ->whereNotIn('id', DB::table('transactions')->pluck('finance_account_id'))
            ->whereNotIn('id', DB::table('finance_transfers')->pluck('from_account_id'))
            ->whereNotIn('id', DB::table('finance_transfers')->pluck('to_account_id'))
            ->delete();

        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropColumn('is_opening_fund');
        });
    }
};
