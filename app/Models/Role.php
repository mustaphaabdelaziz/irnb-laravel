<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;
    use SerializesClubDates;

    protected $fillable = ['key', 'name', 'permissions', 'is_system', 'own_teams_only'];

    /**
     * Permission-controlled modules, grouped as in the sidebar so the role
     * editor reads like the menu. Each App Configuration list is its own
     * module, so a role can get one list (equipment categories) without the
     * others (2026-10).
     */
    public const MODULE_GROUPS = [
        'members' => ['players', 'documents', 'attendance', 'subscriptions'],
        'competitions' => ['teams', 'competitions', 'games'],
        'finance' => ['transactions', 'payment_orders', 'purchase_orders', 'finance', 'reports'],
        'equipment' => ['equipment', 'inventory'],
        'board' => ['board'],
        'social' => ['activities', 'literary_reports'],
        'config' => [
            'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
            'board_roles', 'board_committees', 'equipment_categories', 'storage_locations',
            'game_formats', 'opponents', 'venues', 'activity_types', 'suppliers',
        ],
        'system' => ['users', 'settings'],
    ];

    /** Permission-controlled modules. */
    public const MODULES = [
        'players', 'documents', 'attendance', 'subscriptions',
        'teams', 'competitions', 'games',
        'transactions', 'payment_orders', 'purchase_orders', 'finance', 'reports',
        'equipment', 'inventory',
        'board',
        'activities', 'literary_reports',
        'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
        'board_roles', 'board_committees', 'equipment_categories', 'storage_locations',
        'game_formats', 'opponents', 'venues', 'activity_types', 'suppliers',
        'users', 'settings',
    ];

    /** Canonical actions per module. */
    public const ACTIONS = ['view', 'add', 'edit', 'delete'];

    /**
     * Actions only some modules have, after the canonical four. Approving a
     * payment order is a separate right from editing it (owner, 2026-11-25):
     * the accountant prepares, the president or treasurer approves.
     */
    public const EXTRA_ACTIONS = [
        'payment_orders' => ['approve'],
    ];

    /** @return list<string> The actions a module can be granted. */
    public static function actionsFor(string $module): array
    {
        return [...self::ACTIONS, ...(self::EXTRA_ACTIONS[$module] ?? [])];
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'permissions' => 'array',
            'is_system' => 'boolean',
            // Coaches: edit only the teams they are assigned to for the season (see TeamAccess).
            'own_teams_only' => 'boolean',
        ];
    }

    /** Full matrix: every module granted every action it has. */
    public static function allPermissions(): array
    {
        $all = [];
        foreach (self::MODULES as $module) {
            $all[$module] = self::actionsFor($module);
        }

        return $all;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
