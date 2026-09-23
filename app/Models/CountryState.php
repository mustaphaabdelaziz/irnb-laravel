<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CountryState extends Model
{
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'country_id',
        'external_id',
        'code',
        'name',
        'name_fr',
        'name_ar',
        'ar_name',
        'longitude',
        'latitude',
    ];

    protected $appends = [
        'localized_name',
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
