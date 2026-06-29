<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abbreviation',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->name.' ('.$this->abbreviation.')');
    }
}
