<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a match report's timeline: a goal (scorer + assist), a sanction
 * (subtype = a key of the match's sanction_types), or a substitution
 * (player_id comes on, related_player_id goes off). An opponent's own goal
 * is our goal with no scorer.
 */
class GameEvent extends Model
{
    public const TYPES = ['goal', 'sanction', 'substitution'];

    public const GOAL_KINDS = ['normal', 'penalty', 'free_kick', 'opponent_own_goal'];

    protected $fillable = ['game_id', 'type', 'subtype', 'period', 'minute', 'player_id', 'related_player_id'];

    protected function casts(): array
    {
        return ['period' => 'integer', 'minute' => 'integer'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function relatedPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'related_player_id');
    }
}
