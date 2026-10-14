<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'abbreviation'];

    /** The sport this position belongs to; null for positions not tied to a sport yet. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /** Players who list this position as one of their other positions. */
    public function otherPlayers(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'player_other_positions');
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->name.' ('.$this->abbreviation.')');
    }
}
