<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stadium or hall. `is_home` marks the club's own grounds (offered first). */
class Venue extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'address', 'is_home'];

    protected function casts(): array
    {
        return ['is_home' => 'boolean'];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
