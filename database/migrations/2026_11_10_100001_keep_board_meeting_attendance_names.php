<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removing someone from the board kept nothing of their meeting attendance
 * (cascade delete). Each row now carries the name and role it was taken under,
 * and the member link is cleared instead of deleting the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->string('member_name')->nullable()->after('board_member_id');
            $table->string('member_role', 100)->nullable()->after('member_name');
        });

        DB::table('board_members')->orderBy('id')->select(['id', 'name', 'role'])->each(function ($m) {
            DB::table('meeting_attendances')->where('board_member_id', $m->id)
                ->update(['member_name' => $m->name, 'member_role' => $m->role]);
        });

        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->dropForeign(['board_member_id']);
        });
        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('board_member_id')->nullable()->change();
            $table->foreign('board_member_id')->references('id')->on('board_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('meeting_attendances')->whereNull('board_member_id')->delete();

        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->dropForeign(['board_member_id']);
        });
        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('board_member_id')->nullable(false)->change();
            $table->foreign('board_member_id')->references('id')->on('board_members')->cascadeOnDelete();
            $table->dropColumn(['member_name', 'member_role']);
        });
    }
};
