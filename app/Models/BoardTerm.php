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

    /**
     * The term before this one: by start date when both have one, else by id.
     * Computed in PHP (a club has a handful of terms) so NULL dates sort the same on every database.
     */
    public function previous(): ?self
    {
        return self::whereKeyNot($this->id)->get()
            ->filter(fn (self $t) => $this->start_date && $t->start_date
                ? $t->start_date->lt($this->start_date)
                : $t->id < $this->id)
            ->sortByDesc(fn (self $t) => sprintf('%s-%010d', $t->start_date?->format('Y-m-d') ?? '0000-00-00', $t->id))
            ->first();
    }

    public function members(): HasMany
    {
        return $this->hasMany(BoardMember::class);
    }
}
