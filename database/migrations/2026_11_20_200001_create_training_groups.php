<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training groups inside a branch + category. `group_id` null on a schedule
 * or a session = the whole category, so nothing changes for a category
 * without groups. No foreign key on the two `group_id` columns: adding one
 * rebuilds the sqlite table, and training_sessions has cascading children;
 * AttendanceGroupController::destroy() refuses a group still in use.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL
 * is not transactional) finishes on the next run.
 */
return new class extends Migration
{
    private const TAGGED = ['training_schedules', 'training_sessions'];

    public function up(): void
    {
        if (! Schema::hasTable('training_groups')) {
            Schema::create('training_groups', function (Blueprint $t) {
                $t->id();
                // null = the whole club, as on the other attendance tables.
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->foreignId('category_id')->constrained();
                $t->string('name', 60);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('training_group_members')) {
            Schema::create('training_group_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('training_group_id')->constrained()->cascadeOnDelete();
                $t->foreignId('player_id')->constrained()->cascadeOnDelete();
                // 'Y-m-d'. from_date null = since always; to_date null = still a member.
                $t->date('from_date')->nullable();
                $t->date('to_date')->nullable();
                $t->timestamps();
                $t->index(['player_id', 'training_group_id'], 'training_group_members_player_group_index');
            });
        }

        foreach (self::TAGGED as $table) {
            if (! Schema::hasColumn($table, 'group_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('group_id')->nullable());
            }
            if (! Schema::hasIndex($table, "{$table}_group_id_index")) {
                Schema::table($table, fn (Blueprint $t) => $t->index('group_id', "{$table}_group_id_index"));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TAGGED as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_group_id_index");
                $t->dropColumn('group_id');
            });
        }
        Schema::dropIfExists('training_group_members');
        Schema::dropIfExists('training_groups');
    }
};
