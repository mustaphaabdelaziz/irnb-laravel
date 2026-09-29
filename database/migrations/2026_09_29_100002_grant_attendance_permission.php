<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new `attendance` module. Whoever could work on players keeps working:
     * each role gets the same actions on attendance as it has on players, and
     * the two system admin roles get everything. God admins need nothing (they
     * read Role::MODULES). A migration, not a seeder, because the desktop build
     * runs `migrate` on boot and never seeds. Idempotent: an existing
     * `attendance` key is left alone.
     */
    private const ADMIN_ROLES = ['superadmin', 'administrator'];

    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'key', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            if (array_key_exists('attendance', $permissions)) {
                continue;
            }

            $actions = in_array($role->key, self::ADMIN_ROLES, true) ? self::ACTIONS : ($permissions['players'] ?? []);
            if ($actions === []) {
                continue;
            }

            $permissions['attendance'] = array_values($actions);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            unset($permissions['attendance']);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
