<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardTerm extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'start_date', 'end_date', 'is_current'];

    protected function casts(): array
    {
        return [
            // Y-m-d so the value drops straight into <input type="date">;
            // a full timestamp leaves the field blank and re-sends the old date.
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    /** The term marked current, else the newest; null when the club has none. */
    public static function current(): ?self
    {
        return self::where('is_current', true)->first() ?? self::latest('id')->first();
    }

    public function members(): HasMany
    {
        return $this->hasMany(BoardMember::class);
    }
}
