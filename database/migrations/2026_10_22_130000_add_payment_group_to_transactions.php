<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments taken together at the desk (several subscriptions paid at once)
     * share a group id, so one receipt can list them all with their total.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('payment_group')->nullable()->after('player_subscription_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_group']);
            $table->dropColumn('payment_group');
        });
    }
};
