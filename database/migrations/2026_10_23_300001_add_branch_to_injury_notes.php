<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Injury details per branch. A two-sport player's football and swimming
 * spells are separate (InjurySpells), so a detail is keyed by player,
 * branch and the spell's start. Same rules as
 * 2026_10_23_100001_add_branch_to_attendance_tables: `branch_id` null = the
 * whole club, no foreign-key constraint (BranchController::destroy() deletes
 * a branch's details), and the unique key on the virtual `branch_key`
 * (0 = whole club) since NULLs never collide in a unique index.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL
 * is not transactional) finishes on the next run, and a second run changes
 * nothing. Literal status values, not the enums: a migration must not move
 * when the code does.
 */
return new class extends Migration
{
    private const UNIQUE = 'injury_notes_branch_spell_unique';

    private const OLD_UNIQUE = 'injury_notes_player_id_start_date_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('injury_notes', 'branch_id')) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
        }
        if (! Schema::hasIndex('injury_notes', 'injury_notes_branch_id_index')) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->index('branch_id', 'injury_notes_branch_id_index'));
        }
        if (! Schema::hasColumn('injury_notes', 'branch_key')) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->unsignedBigInteger('branch_key')->virtualAs('coalesce(branch_id, 0)'));
        }
        // Starts with player_id: MySQL's player_id foreign key keeps an index once the old key is gone.
        if (! Schema::hasIndex('injury_notes', self::UNIQUE)) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->unique(['player_id', 'branch_key', 'start_date'], self::UNIQUE));
        }
        if (Schema::hasIndex('injury_notes', self::OLD_UNIQUE)) {
            Schema::table('injury_notes', fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE));
        }

        $this->backfill();
    }

    /**
     * Every detail saved before details had a branch goes to the branch of
     * the session holding the player's injury mark on the spell's start (its
     * first mark; earliest session that day), else to the branch with the
     * most players (ties: lowest id), as the other attendance tables were.
     * No branch at all: stays null. Few rows, one lookup each.
     */
    public function backfill(): void
    {
        $largest = DB::table('branches')
            ->leftJoin('branch_player', 'branch_player.branch_id', '=', 'branches.id')
            ->groupBy('branches.id')
            ->orderByRaw('count(branch_player.player_id) desc')
            ->orderBy('branches.id')
            ->value('branches.id');
        if ($largest === null) {
            return;
        }

        $notes = DB::table('injury_notes')->whereNull('branch_id')->orderBy('id')->get(['id', 'player_id', 'start_date']);
        foreach ($notes as $note) {
            $branchId = DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->where('attendances.player_id', $note->player_id)
                ->where('training_sessions.date', $note->start_date)
                ->where('training_sessions.state', 'held')
                ->whereNotNull('training_sessions.branch_id')
                ->where(fn ($q) => $q->where('attendances.status', 'not_training')
                    ->orWhere(fn ($q) => $q->where('attendances.status', 'absent_excused')->where('attendances.reason', 'injury')))
                ->orderBy('training_sessions.start_time')
                ->orderBy('training_sessions.id')
                ->value('training_sessions.branch_id');
            DB::table('injury_notes')->where('id', $note->id)->update(['branch_id' => $branchId ?? $largest]);
        }
    }

    public function down(): void
    {
        Schema::table('injury_notes', function (Blueprint $t) {
            $t->unique(['player_id', 'start_date'], self::OLD_UNIQUE);
            $t->dropUnique(self::UNIQUE);
        });
        Schema::table('injury_notes', fn (Blueprint $t) => $t->dropColumn('branch_key'));
        Schema::table('injury_notes', function (Blueprint $t) {
            $t->dropIndex('injury_notes_branch_id_index');
            $t->dropColumn('branch_id');
        });
    }
};
