<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board organigram (2026-10-27): roles get a chart level (top / bureau /
 * member), and members sit on committees as head, deputy or member.
 *
 * Committees are their own App Configuration list (module `board_committees`);
 * as with the 2026-10-07 split, the new right starts empty except on the
 * system roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_roles', function (Blueprint $table) {
            $table->string('level', 20)->default('member')->after('label');
        });
        DB::table('board_roles')->where('name', 'president')->update(['level' => 'top']);
        DB::table('board_roles')->whereIn('name', ['vice_president', 'secretary', 'treasurer'])->update(['level' => 'bureau']);

        Schema::create('board_committees', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('board_committee_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_committee_id')->constrained('board_committees')->cascadeOnDelete();
            $table->foreignId('board_member_id')->constrained('board_members')->cascadeOnDelete();
            $table->string('position', 20)->default('member');
            $table->timestamps();

            $table->unique(['board_committee_id', 'board_member_id']);
        });

        $all = ['view', 'add', 'edit', 'delete'];
        DB::table('roles')->where('is_system', true)->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) use ($all) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                $permissions['board_committees'] = $all;

                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            unset($permissions['board_committees']);

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });

        Schema::dropIfExists('board_committee_member');
        Schema::dropIfExists('board_committees');
        Schema::table('board_roles', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
