<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The competitions modules. Owner's rule for new rights: they start empty,
 * except the system roles (full access) and the seeded coach role, which gets
 * what a coach does on match days — limited to the teams they coach
 * (`own_teams_only`). A migration because the desktop build never seeds.
 * Idempotent: a module a role already has is left alone.
 */
return new class extends Migration
{
    private const MODULES = ['teams', 'competitions', 'games', 'game_formats', 'opponents', 'venues'];

    private const ALL = ['view', 'add', 'edit', 'delete'];

    private const COACH = [
        'teams' => ['view', 'edit'],
        'competitions' => ['view', 'add', 'edit'],
        'games' => ['view', 'add', 'edit'],
        'game_formats' => ['view'],
        'opponents' => ['view', 'add'],
        'venues' => ['view', 'add'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'key', 'is_system', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $grant = $role->is_system ? array_fill_keys(self::MODULES, self::ALL) : ($role->key === 'coach' ? self::COACH : []);

            foreach ($grant as $module => $actions) {
                $permissions[$module] ??= $actions;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($permissions),
                'own_teams_only' => $role->key === 'coach',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::MODULES as $module) {
                unset($permissions[$module]);
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'own_teams_only' => false]);
        }
    }
};
