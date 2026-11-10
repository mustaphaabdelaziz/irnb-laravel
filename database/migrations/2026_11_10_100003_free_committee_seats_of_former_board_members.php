<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A member marked «former» kept their committee seats, which still counted
 * against the one-head / two-deputies limits although the organigram hides them.
 * Marking someone former now frees their seats; this clears the existing ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('board_committee_member')
            ->whereIn('board_member_id', DB::table('board_members')->where('status', 'former')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        // Deleted seats cannot be told apart from seats that never existed: nothing to restore.
    }
};
