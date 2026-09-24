<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of subscription: an annual one bound to a season (its `year` is the
 * season's END year, so the existing 2026 rows read as 2025/2026) and an
 * exceptional one-off charge such as a club t-shirt, which has no year.
 *
 * Each attached category may also carry its own student/worker price; a null
 * falls back to the subscription's default price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('kind', 20)->default('annual')->after('name');
            $table->unsignedSmallInteger('year')->nullable()->change();
        });

        Schema::table('category_subscription', function (Blueprint $table) {
            $table->decimal('amount_student', 12, 2)->nullable();
            $table->decimal('amount_worker', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('category_subscription', function (Blueprint $table) {
            $table->dropColumn(['amount_student', 'amount_worker']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
