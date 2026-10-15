<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountryStateCommune extends Model
{
    use HasFactory;
    use SerializesClubDates;

    protected $fillable = [
        'country_state_id',
        'name',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(CountryState::class, 'country_state_id');
    }
}
