<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Social activities (2026-11-01): volunteer campaigns, awareness sessions,
 * summer camps… recorded after the fact with participants, a written report,
 * photos and attached files, for the year-end literary report.
 *
 * Two new rights: `activities` (sidebar section) and `activity_types` (App
 * Configuration list). As with every new right, they start empty except on
 * the system roles.
 */
return new class extends Migration
{
    private const TYPES = ['حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'];

    private const MODULES = ['activities', 'activity_types'];

    public function up(): void
    {
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('activity_types')->insert(array_map(
            fn (string $name, int $i) => ['name' => $name, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now],
            self::TYPES,
            array_keys(self::TYPES),
        ));

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('activity_type_id')->constrained('activity_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('location')->nullable();
            $table->text('partners')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('responsible_board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->decimal('cost', 12, 2)->nullable();
            $table->unsignedInteger('beneficiaries_count')->default(0);
            $table->unsignedInteger('external_volunteers_count')->default(0);
            $table->text('description')->nullable();
            $table->longText('report')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('starts_on');
        });

        Schema::create('activity_category', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'category_id']);
        });

        Schema::create('activity_player', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'player_id']);
        });

        Schema::create('activity_board_member', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_member_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'board_member_id']);
        });

        Schema::create('activity_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path');
            $table->string('caption')->nullable();
            $table->boolean('in_report')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('activity_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        $all = ['view', 'add', 'edit', 'delete'];
        DB::table('roles')->where('is_system', true)->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) use ($all) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                foreach (self::MODULES as $module) {
                    $permissions[$module] = $all;
                }

                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::MODULES as $module) {
                unset($permissions[$module]);
            }

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });

        Schema::dropIfExists('activity_files');
        Schema::dropIfExists('activity_photos');
        Schema::dropIfExists('activity_board_member');
        Schema::dropIfExists('activity_player');
        Schema::dropIfExists('activity_category');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('activity_types');
    }
};
