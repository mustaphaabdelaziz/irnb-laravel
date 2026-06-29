<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create polymorphic equipment_rentals table
        Schema::create('equipment_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->morphs('rentable'); // rentable_type + rentable_id (Player or User)
            $table->timestamp('checkout_date')->useCurrent();
            $table->date('due_date')->nullable();
            $table->timestamp('return_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['equipment_item_id', 'return_date']);
        });

        // 2. Remove rental columns from equipment_items (now tracked in equipment_rentals)
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropForeign(['rented_to_player_id']);
            $table->dropIndex(['rented_to_player_id', 'status']);
            $table->dropIndex(['due_date', 'status']);
            $table->dropColumn(['rented_to_player_id', 'last_checkout_date', 'due_date']);
        });

        // 3. Fix equipment_histories: remove updated_at (immutable audit trail)
        Schema::table('equipment_histories', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });

        // 4. Add standalone fiscal_year index on transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['fiscal_year']);
        });

        Schema::table('equipment_histories', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('equipment_items', function (Blueprint $table) {
            $table->foreignId('rented_to_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamp('last_checkout_date')->nullable();
            $table->date('due_date')->nullable();
            $table->index(['rented_to_player_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::dropIfExists('equipment_rentals');
    }
};
