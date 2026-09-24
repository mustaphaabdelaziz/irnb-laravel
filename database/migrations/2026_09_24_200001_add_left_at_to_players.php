<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The day a member left the club. Only meaningful while the member's status
     * is the built-in "left" one (code `left`); Player keeps the two in step.
     * Members already marked "left" before this column existed keep a null date:
     * the day they left was never recorded, and inventing one would skew the
     * leavers-per-month statistics.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->date('left_at')->nullable()->after('status_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex(['left_at']);
            $table->dropColumn('left_at');
        });
    }
};
