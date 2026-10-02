<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The player statuses a session's expected roster is drawn from, chosen when
 * the session is added by hand. Null (every generated session) means the
 * attendance settings' default set, read when the roster is built. A plain
 * ADD COLUMN: training_sessions is never rebuilt (attendances cascade from it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('training_sessions', 'roster_status_ids')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->json('roster_status_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('training_sessions', 'roster_status_ids')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->dropColumn('roster_status_ids');
            });
        }
    }
};
