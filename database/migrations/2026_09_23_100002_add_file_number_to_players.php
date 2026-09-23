<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number written on a player's paper folder. One club-wide sequence,
     * assigned once and never reused: the cabinet is sorted by it, so a number
     * that moved would send someone to the wrong drawer.
     *
     * Existing players are numbered in the order they joined, so the oldest
     * members sit at the front of the first drawer.
     */
    public function up(): void
    {
        // The desktop build runs `migrate` on every boot, and SQLite does not
        // roll back DDL: if a previous run got this far and then died before
        // being recorded, the column (and/or its unique index) may already
        // exist. Guard each piece so a re-run never throws "duplicate column
        // name" / "index already exists" — and never re-adds either.
        if (! Schema::hasColumn('players', 'file_number')) {
            Schema::table('players', function (Blueprint $table) {
                $table->unsignedInteger('file_number')->nullable()->after('membership_id');
            });
        }

        if (! Schema::hasIndex('players', ['file_number'], 'unique')) {
            Schema::table('players', function (Blueprint $table) {
                $table->unique('file_number');
            });
        }

        // Only number rows that don't already have a number, continuing
        // after the current max — never renumbering a row a previous
        // (possibly partial) run already assigned. On a fresh run every row
        // is null and the max is 0, so this numbers 1, 2, 3… exactly as
        // before.
        $next = (int) DB::table('players')->max('file_number') + 1;

        $rows = DB::table('players')
            ->whereNull('file_number')
            ->select('id')
            ->orderByRaw('join_year is null, join_year, membership_id, id')
            ->get();

        foreach ($rows as $row) {
            DB::table('players')->where('id', $row->id)->update(['file_number' => $next++]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('players', 'file_number')) {
            return;
        }

        if (Schema::hasIndex('players', ['file_number'], 'unique')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropUnique(['file_number']);
            });
        }

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('file_number');
        });
    }
};
