<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The week, agenda and timeline views read every category's sessions by
 * date range; the existing indexes all start with category_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('training_sessions', 'training_sessions_date_index')) {
            return;
        }

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
    }
};
