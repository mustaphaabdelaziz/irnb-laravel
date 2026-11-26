<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The player statuses a subscription is limited to. No row: every status
 * but `left` (the default, so existing subscriptions keep their reach).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_status_subscription', function (Blueprint $table) {
            $table->foreignId('player_status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->primary(['player_status_id', 'subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_status_subscription');
    }
};
