<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->boolean('is_exempt')->default(false)->after('is_mandatory');
        });

        // Preserve existing exemptions: any subscription that currently has a
        // non-archived donation payment was exempt under the old isExempt() rule.
        DB::table('player_subscriptions')
            ->whereIn('id', function ($q) {
                $q->select('player_subscription_id')->from('transactions')
                    ->where('category', 'donation')
                    ->where('archived', false)
                    ->whereNotNull('player_subscription_id');
            })
            ->update(['is_exempt' => true]);
    }

    public function down(): void
    {
        Schema::table('player_subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_exempt');
        });
    }
};
