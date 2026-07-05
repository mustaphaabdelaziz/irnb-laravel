<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('player_subscription_id')->nullable()->after('related_entity_id')
                ->constrained('player_subscriptions')->nullOnDelete();
            $table->index('player_subscription_id');
        });

        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(true)->after('status_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('player_subscription_id');
        });
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_mandatory');
        });
    }
};
