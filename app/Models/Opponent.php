<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A club we play against — a saved list so head-to-head history adds up. */
class Opponent extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'city', 'logo_url', 'logo_filename'];

    protected $hidden = ['logo_filename'];

    /** Host-relative /media URL, whatever was stored (see App\Support\Media). */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => Media::path($value));
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
