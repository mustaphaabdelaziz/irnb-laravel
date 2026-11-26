<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merging duplicate players. Owner's rule for new rights: they start empty
 * except the system roles (full access). A migration because the desktop
 * build never seeds. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $players = $permissions['players'] ?? ['view', 'add', 'edit', 'delete'];
            if (! in_array('merge', $players, true)) {
                $players[] = 'merge';
            }
            $permissions['players'] = array_values($players);
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
            if (! isset($permissions['players'])) {
                continue;
            }
            $permissions['players'] = array_values(array_diff($permissions['players'], ['merge']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
