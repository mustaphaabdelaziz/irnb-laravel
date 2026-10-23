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

    /**
     * Every step checks its own work, so a run that failed half way (MySQL
     * DDL is not transactional) finishes on the next run, and a second run
     * changes nothing.
     */
    public function up(): void
    {
        foreach (self::KEYS as $table => $key) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
            }
            if (! Schema::hasIndex($table, "{$table}_branch_id_index")) {
                Schema::table($table, fn (Blueprint $t) => $t->index('branch_id', "{$table}_branch_id_index"));
            }
            if ($key === null) {
                continue;
            }
            if (! Schema::hasColumn($table, 'branch_key')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('branch_key')->virtualAs('coalesce(branch_id, 0)'));
            }
            // MySQL needs an index starting with category_id for its foreign key once the old unique is gone.
            if (! Schema::hasIndex($table, "{$table}_category_id_index")) {
                Schema::table($table, fn (Blueprint $t) => $t->index('category_id', "{$table}_category_id_index"));
            }
            if (! Schema::hasIndex($table, self::uniqueName($table))) {
                Schema::table($table, fn (Blueprint $t) => $t->unique(['branch_key', ...$key], self::uniqueName($table)));
            }
            if (Schema::hasIndex($table, self::oldUniqueName($table, $key))) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique(self::oldUniqueName($table, $key)));
            }
        }

        $this->backfill();
    }

    /** The branch-aware unique key, named by hand: the generated names pass MySQL's 64-character limit. */
    private static function uniqueName(string $table): string
    {
        return "{$table}_branch_slot_unique";
    }

    /** Laravel's default name for the unique key the table was created with. */
    private static function oldUniqueName(string $table, array $key): string
    {
        return strtolower($table.'_'.implode('_', $key).'_unique');
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
                Schema::table($table, function (Blueprint $t) use ($table, $key) {
                    $t->unique($key, self::oldUniqueName($table, $key));
                    $t->dropUnique(self::uniqueName($table));
                    $t->dropIndex("{$table}_category_id_index");
                });
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('branch_key'));
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_branch_id_index");
                $t->dropColumn('branch_id');
            });
        }
    }
};
