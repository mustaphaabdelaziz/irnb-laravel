<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'sort_order',
        'is_active',
    ];

    protected $appends = [
        'localized_name',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The status name in the current app locale, falling back to the base name. */
    public function getLocalizedNameAttribute(): string
    {
        $column = 'name_'.app()->getLocale();

        return $this->{$column} ?: $this->name;
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'status_id');
    }
}
