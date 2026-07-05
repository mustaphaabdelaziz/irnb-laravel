<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Managed board roles (president, vice_president, ...). board_members.role
        //    stays a string matched by name — no FK, so renaming a role cascades in code.
        Schema::create('board_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['name' => 'president', 'sort_order' => 1],
            ['name' => 'vice_president', 'sort_order' => 2],
            ['name' => 'secretary', 'sort_order' => 3],
            ['name' => 'treasurer', 'sort_order' => 4],
            ['name' => 'member', 'sort_order' => 5],
        ];
        // Include any role names already used by existing members.
        $existingRoles = DB::table('board_members')->whereNotNull('role')->distinct()->pluck('role')->all();
        $known = array_column($defaults, 'name');
        foreach (array_diff($existingRoles, $known) as $i => $name) {
            $defaults[] = ['name' => $name, 'sort_order' => 6 + $i];
        }
        foreach ($defaults as $r) {
            DB::table('board_roles')->insertOrIgnore($r + ['created_at' => $now, 'updated_at' => $now]);
        }

        // 2. Board terms (mandates). Each term groups members with roles.
        Schema::create('board_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->timestamps();
        });

        // Seed a starting term from existing members' term dates (or a generic one).
        $start = DB::table('board_members')->min('term_start');
        $end = DB::table('board_members')->max('term_end');
        $label = $start ? (substr($start, 0, 4).($end ? '–'.substr($end, 0, 4) : '')) : (string) $now->year;
        $termId = DB::table('board_terms')->insertGetId([
            'name' => $label,
            'start_date' => $start,
            'end_date' => $end,
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 3. Extend board_members: link to a player + a term; role becomes a free string.
        Schema::table('board_members', function (Blueprint $table) {
            $table->foreignId('player_id')->nullable()->after('user_id')->constrained('players')->nullOnDelete();
            $table->foreignId('board_term_id')->nullable()->after('player_id')->constrained('board_terms')->nullOnDelete();
        });
        Schema::table('board_members', function (Blueprint $table) {
            $table->string('role')->default('member')->change();
        });

        // Attach every existing member to the seeded current term.
        DB::table('board_members')->update(['board_term_id' => $termId]);
    }

    public function down(): void
    {
        Schema::table('board_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('player_id');
            $table->dropConstrainedForeignId('board_term_id');
        });
        Schema::dropIfExists('board_terms');
        Schema::dropIfExists('board_roles');
    }
};
