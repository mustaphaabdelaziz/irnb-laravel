<?php

namespace App\Models;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A match ("Game": `match` is a reserved word in PHP 8). Its game format is
 * resolved (competition override, else team default) and frozen at creation,
 * so later edits to the format never rewrite history. A game without a
 * competition is a friendly and calls players up from the team roster.
 */
class Game extends Model
{
    protected $fillable = [
        'team_id', 'competition_id', 'season_start_year', 'game_format_id', 'opponent_id', 'venue_id',
        'side', 'round_label', 'date', 'kickoff_time', 'meeting_time', 'meeting_place', 'status',
        'our_score', 'their_score', 'period_scores', 'extra_time_played', 'shootout_us', 'shootout_them',
        'formation_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'side' => GameSide::class,
            'status' => GameStatus::class,
            'period_scores' => 'array',
            'extra_time_played' => 'boolean',
            'our_score' => 'integer',
            'their_score' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'game_format_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Opponent::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }
}
