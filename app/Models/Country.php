<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;
    use SerializesClubDates;

    protected $fillable = [
        'name',
        'code',
        'flag',
    ];

    public function states(): HasMany
    {
        return $this->hasMany(CountryState::class);
    }
}
