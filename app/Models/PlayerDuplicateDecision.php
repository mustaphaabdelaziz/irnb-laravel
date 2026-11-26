<?php

namespace App\Models;

use App\Services\Player\Duplicates\PlayerCards;
use Illuminate\Database\Eloquent\Model;

/**
 * A decision about a possible duplicate pair (player_a_id < player_b_id):
 * dismissed ("not duplicate", owner 2026-11-26: remembered forever, undoable)
 * or merged. Snapshots keep merged rows readable after a player is deleted.
 */
class PlayerDuplicateDecision extends Model
{
    public const DISMISSED = 'dismissed';

    public const MERGED = 'merged';

    protected $fillable = [
        'player_a_id', 'player_b_id', 'status', 'player_a_snapshot', 'player_b_snapshot',
        'kept_player_id', 'reasons', 'score', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'player_a_snapshot' => 'array',
            'player_b_snapshot' => 'array',
            'reasons' => 'array',
            'score' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return array{0:int, 1:int} */
    public static function orderedPair(int $x, int $y): array
    {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    /** The player's card as it is now, kept for when the player is gone. */
    public static function snapshot(Player $player): array
    {
        return PlayerCards::for([$player->id])->get($player->id) ?? [
            'id' => $player->id, 'name' => $player->fullname, 'membership_ids' => [],
            'birthdate' => null, 'picture_url' => null, 'archived' => false,
        ];
    }
}
