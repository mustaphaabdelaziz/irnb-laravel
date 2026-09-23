<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            // A discount is recorded alongside the price, never folded into it, so
            // amount_owed keeps meaning "the real price" and the discount stays
            // visible and reversible. What is actually owed derives from both.
            $table->enum('discount_type', ['percent', 'amount'])->nullable()->after('amount_paid');
            $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
