<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CountryState extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'external_id',
        'name',
        'ar_name',
        'longitude',
        'latitude',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function communes(): HasMany
    {
        return $this->hasMany(CountryStateCommune::class);
    }
}
