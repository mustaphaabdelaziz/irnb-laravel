<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fiscal years — the unit a club's finances are organised and "closed" by.
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('label')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('closing_balance', 14, 2)->nullable();
            $table->decimal('total_income', 14, 2)->nullable();
            $table->decimal('total_expense', 14, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Chart of accounts: a structured list replacing free-text categories.
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['income', 'expense'])->index();
            $table->string('name');
            $table->string('code', 16)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('color', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['type', 'name']);
        });

        // Cash boxes / bank accounts, each with a running balance.
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['cash', 'bank', 'other'])->default('cash');
            $table->string('account_number')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Planned amount per category per fiscal year (budget vs actual).
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->foreignId('finance_category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->decimal('planned_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'finance_category_id']);
        });

        // Link existing transactions into the new structures.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('fiscal_year')->constrained('fiscal_years')->nullOnDelete();
            $table->foreignId('finance_category_id')->nullable()->after('category')->constrained('finance_categories')->nullOnDelete();
            $table->foreignId('finance_account_id')->nullable()->after('payment_account')->constrained('finance_accounts')->nullOnDelete();
        });

        $this->seedDefaults();
    }

    /**
     * Seed sensible default categories + a cash account + the current fiscal
     * year, so every fresh install (incl. a brand-new club) starts usable.
     */
    private function seedDefaults(): void
    {
        $now = now();
        $income = [
            ['Subscriptions', 'SUB', '#10b981'],
            ['Membership Fees', 'MEM', '#14b8a6'],
            ['Donations', 'DON', '#22c55e'],
            ['Grants / Subsidies', 'GRT', '#84cc16'],
            ['Arrears / Debts', 'ARR', '#eab308'],
            ['Events Revenue', 'EVR', '#06b6d4'],
            ['Other Income', 'OIN', '#64748b'],
        ];
        $expense = [
            ['Equipment', 'EQP', '#ef4444'],
            ['Maintenance & Repairs', 'MNT', '#f97316'],
            ['Supplies', 'SUP', '#f59e0b'],
            ['Works / Construction', 'WRK', '#d946ef'],
            ['Utilities', 'UTL', '#8b5cf6'],
            ['Salaries / Wages', 'SAL', '#ec4899'],
            ['Events Expenses', 'EVE', '#3b82f6'],
            ['Administrative', 'ADM', '#6366f1'],
            ['Other Expense', 'OEX', '#64748b'],
        ];

        $rows = [];
        $order = 0;
        foreach ($income as [$name, $code, $color]) {
            $rows[] = ['type' => 'income', 'name' => $name, 'code' => $code, 'color' => $color, 'sort_order' => $order++, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        $order = 0;
        foreach ($expense as [$name, $code, $color]) {
            $rows[] = ['type' => 'expense', 'name' => $name, 'code' => $code, 'color' => $color, 'sort_order' => $order++, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('finance_categories')->insert($rows);

        DB::table('finance_accounts')->insert([
            'name' => 'Cash', 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0,
            'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('fiscal_years')->insert([
            'year' => (int) $now->format('Y'),
            'start_date' => $now->copy()->startOfYear()->toDateString(),
            'end_date' => $now->copy()->endOfYear()->toDateString(),
            'status' => 'open', 'opening_balance' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropConstrainedForeignId('finance_category_id');
            $table->dropConstrainedForeignId('finance_account_id');
        });
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('finance_accounts');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('fiscal_years');
    }
};
