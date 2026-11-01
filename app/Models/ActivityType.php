<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of social activity (volunteer campaign, summer camp…). User data, not translated. */
class ActivityType extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'sort_order'];

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
