<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named line-up shape (4-3-3) of a game format: one slot {label, x, y} per player on the field, x/y in 0–100. */
class Formation extends Model
{
    use SerializesClubDates;

    protected $fillable = ['game_format_id', 'name', 'slots'];

    protected function casts(): array
    {
        return ['slots' => 'array'];
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'game_format_id');
    }
}
