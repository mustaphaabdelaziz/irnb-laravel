<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders. Owner's rule for new rights: they start empty except the
 * system roles (full access). A migration because the desktop build never
 * seeds. Idempotent: a module a role already has is left alone.
 */
return new class extends Migration
{
    private const GRANT = [
        'purchase_orders' => ['view', 'add', 'edit', 'delete'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::GRANT as $module => $actions) {
                $permissions[$module] ??= $actions;
            }
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
            foreach (array_keys(self::GRANT) as $module) {
                unset($permissions[$module]);
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
