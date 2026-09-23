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
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->index(['branch_id', 'type', 'is_active']);
        });

        Schema::create('finance_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('transfer_date');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transfer_date', 'created_at']);
            $table->index(['from_account_id', 'transfer_date']);
            $table->index(['to_account_id', 'transfer_date']);
        });

        // Give every existing branch its own register immediately. The original
        // unassigned "Cash" account is retained as the club-wide register so
        // historical transactions keep their original meaning.
        $now = now();
        $nextOrder = (int) DB::table('finance_accounts')->max('sort_order');

        foreach (DB::table('branches')->orderBy('id')->get(['id', 'name']) as $branch) {
            DB::table('finance_accounts')->insert([
                'branch_id' => $branch->id,
                'name' => $branch->name.' Cash Register',
                'type' => 'cash',
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

    public function down(): void
    {
        Schema::dropIfExists('finance_transfers');

        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'type', 'is_active']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
