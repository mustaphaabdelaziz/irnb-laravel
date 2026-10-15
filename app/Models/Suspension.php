<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A match ban. source auto|manual; kind matches|dates. Served counts are never
 * stored — SuspensionStatus works them out from the played matches.
 */
class Suspension extends Model
{
    protected $fillable = [
        'player_id', 'competition_id', 'team_id', 'source', 'kind', 'matches_count',
        'starts_on', 'ends_on', 'cause', 'game_id', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['matches_count' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
