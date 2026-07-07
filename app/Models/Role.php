<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'permissions', 'is_system'];

    /** Permission-controlled modules. */
    public const MODULES = [
        'players', 'subscriptions', 'transactions', 'finance', 'reports',
        'equipment', 'inventory', 'board', 'users', 'categories', 'settings',
    ];

    /** Canonical actions per module. */
    public const ACTIONS = ['view', 'add', 'edit', 'delete'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /** Full matrix: every module granted every action. */
    public static function allPermissions(): array
    {
        return array_fill_keys(self::MODULES, self::ACTIONS);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
