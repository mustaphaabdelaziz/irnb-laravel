<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance per branch (sport). `branch_id` null = the whole club, so a
 * club without branches keeps working. No foreign-key constraint: adding
 * one rebuilds the sqlite table, and training_sessions has cascading
 * children; BranchController::destroy() refuses a branch still in use.
 *
 * NULLs never collide in a unique index, and the generator relies on
 * insertOrIgnore against the slot key, so the keys use the virtual
 * `branch_key` (0 = whole club) instead of `branch_id`.
 */
return new class extends Migration
{
    /** Each table's old unique key, which gains the branch (null: no unique key). */
    private const KEYS = [
        'training_sessions' => ['category_id', 'date', 'start_time'],
        'training_schedules' => null,
        'deleted_session_slots' => ['category_id', 'date', 'start_time'],
        'preseason_targets' => ['category_id', 'season_start_year'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $key) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable()->index());
            }
            if ($key === null || Schema::hasColumn($table, 'branch_key')) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('branch_key')->virtualAs('coalesce(branch_id, 0)'));
            Schema::table($table, function (Blueprint $t) use ($key) {
                // MySQL needs an index starting with category_id for its foreign key once the old unique is gone.
                $t->index('category_id');
                $t->unique(['branch_key', ...$key]);
                $t->dropUnique($key);
            });
        }

        $this->backfill();
    }

    /** Every row made before branches mattered goes to the branch with the most players (ties: lowest id). */
    public function backfill(): void
    {
        $branchId = DB::table('branches')
            ->leftJoin('branch_player', 'branch_player.branch_id', '=', 'branches.id')
            ->groupBy('branches.id')
            ->orderByRaw('count(branch_player.player_id) desc')
            ->orderBy('branches.id')
            ->value('branches.id');
        if ($branchId === null) {
            return;
        }

        foreach (array_keys(self::KEYS) as $table) {
            DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branchId]);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $key) {
            if ($key !== null) {
                Schema::table($table, function (Blueprint $t) use ($key) {
                    $t->unique($key);
                    $t->dropUnique(['branch_key', ...$key]);
                    $t->dropIndex(['category_id']);
                });
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('branch_key'));
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['branch_id']);
                $t->dropColumn('branch_id');
            });
        }
    }
};
