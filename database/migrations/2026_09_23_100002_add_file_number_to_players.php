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
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedInteger('file_number')->nullable()->unique()->after('membership_id');
        });

        $next = 1;

        $rows = DB::table('players')
            ->select('id')
            ->orderByRaw('join_year is null, join_year, membership_id, id')
            ->get();

        foreach ($rows as $row) {
            DB::table('players')->where('id', $row->id)->update(['file_number' => $next++]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['file_number']);
            $table->dropColumn('file_number');
        });
    }
};
