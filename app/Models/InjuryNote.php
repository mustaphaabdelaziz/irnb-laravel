<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Details of one injury spell (see InjurySpells), keyed by the player, the
 * branch whose marks build the spell (null = the whole club) and the
 * spell's start date. Dates are 'Y-m-d' strings; no date casts.
 */
class InjuryNote extends Model
{
    use SerializesClubDates;

    protected $fillable = ['player_id', 'branch_id', 'start_date', 'body_part', 'description', 'returned_on', 'created_by'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return array{id: int, start_date: string, body_part: ?string, description: ?string, returned_on: ?string} */
    public function toDetail(): array
    {
        return [
            'id' => $this->id,
            'start_date' => $this->start_date,
            'body_part' => $this->body_part,
            'description' => $this->description,
            'returned_on' => $this->returned_on,
        ];
    }
}
