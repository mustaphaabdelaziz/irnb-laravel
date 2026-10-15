<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's part in one game: called up or not (with a reason), and — from
 * phase 2 — lineup role, shirt, position, minutes, rating. A row exists only
 * for a called-up player or one left out with a reason.
 */
class GamePlayer extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'game_id', 'player_id', 'called_up', 'not_called_reason', 'role', 'shirt_number', 'position_id',
        'slot', 'is_captain', 'is_goalkeeper', 'minutes_played', 'rating', 'coach_note', 'is_mvp',
        'pos_x', 'pos_y', 'shirt_override', 'sets_played', 'played',
    ];

    protected function casts(): array
    {
        return [
            'called_up' => 'boolean',
            'is_captain' => 'boolean',
            'is_goalkeeper' => 'boolean',
            'is_mvp' => 'boolean',
            'shirt_override' => 'boolean',
            'played' => 'boolean',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'sets_played' => 'integer',
            'minutes_played' => 'integer',
            'rating' => 'integer',
            'slot' => 'integer',
            'shirt_number' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
