<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new `documents` module (player documents: view / add / edit /
     * delete). The two system admin roles get it in full, as they have every
     * other module; every other role gets nothing until an admin grants it —
     * documents carry medical and identity papers.
     *
     * God admins (legacy admin/superadmin privilege) need nothing here: they
     * short-circuit to Role::allPermissions(), which reads Role::MODULES. A
     * fresh install's RoleSeeder also uses allPermissions().
     *
     * A migration, not the seeder, because the desktop build runs `migrate` on
     * every boot and never runs seeders. Idempotent: it only ever sets the one
     * key, so re-running it after a partial failure is harmless.
     */
    private const ADMIN_ROLES = ['superadmin', 'administrator'];

    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->whereIn('key', self::ADMIN_ROLES)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $permissions['documents'] = self::ACTIONS;

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->whereIn('key', self::ADMIN_ROLES)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            unset($permissions['documents']);

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
