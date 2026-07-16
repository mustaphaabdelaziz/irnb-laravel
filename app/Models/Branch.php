<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'description',
    ];

    protected $appends = [
        'localized_name',
    ];

    /**
     * The branch name in the current app locale, falling back to the base name.
     */
    public function getLocalizedNameAttribute(): string
    {
        $column = 'name_'.app()->getLocale();

        return $this->{$column} ?: $this->name;
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'branch_player');
    }

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'branch_subscription');
    }
}
