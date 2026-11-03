<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room for FB-2022-00017. Not on SQLite: it ignores string lengths, and
 * ->change() would rebuild players, whose children cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('players', fn (Blueprint $table) => $table->string('membership_id', 24)->change());
        }
    }

    public function down(): void
    {
        // Longer IDs may exist by now, so the column keeps its width.
    }
};
