<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Each App Configuration list became its own permission module (branches,
 * positions, player_statuses, document_types, jobs, board_roles,
 * equipment_categories, storage_locations); `categories` now covers the
 * categories list only and `board` no longer covers board roles.
 *
 * Owner's choice: the new rights start empty, except on the system roles
 * (Administrator, Superadmin), which keep full access to everything.
 */
return new class extends Migration
{
    private const NEW_MODULES = [
        'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
        'board_roles', 'equipment_categories', 'storage_locations',
    ];

    public function up(): void
    {
        $all = ['view', 'add', 'edit', 'delete'];

        DB::table('roles')->where('is_system', true)->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) use ($all) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                foreach (self::NEW_MODULES as $module) {
                    $permissions[$module] = $all;
                }

                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::NEW_MODULES as $module) {
                unset($permissions[$module]);
            }

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });
    }
};
