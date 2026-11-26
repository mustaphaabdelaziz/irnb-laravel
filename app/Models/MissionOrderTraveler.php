<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a mission order: an app user (a coach), a board member, a
 * player, or a typed name (type null). Name and function are copies taken
 * when the order was written and stay editable — the sheet prints them.
 */
class MissionOrderTraveler extends Model
{
    use SerializesClubDates;

    public const USER = 'user';

    public const BOARD_MEMBER = 'board_member';

    public const PLAYER = 'player';

    public const TYPES = [self::USER, self::BOARD_MEMBER, self::PLAYER];

    protected $fillable = ['mission_order_id', 'traveler_type', 'traveler_id', 'name', 'function', 'sort_order'];

    protected function casts(): array
    {
        return [
            'mission_order_id' => 'integer',
            'traveler_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function missionOrder(): BelongsTo
    {
        return $this->belongsTo(MissionOrder::class);
    }
}
