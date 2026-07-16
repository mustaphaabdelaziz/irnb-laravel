<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            // Free-text name for obligations that aren't tied to a subscription plan,
            // e.g. previous/manual debts carried over from before the app.
            $table->string('label')->nullable()->after('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
