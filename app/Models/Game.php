<?php

namespace App\Models;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A match ("Game": `match` is a reserved word in PHP 8). Its game rules are copied from the format (competition override, else team
 * default) into `rules` at creation, so later edits to the format never
 * rewrite history. A game without a competition is a friendly and calls
 * players up from the team roster.
 */
class Game extends Model
{
    protected $fillable = [
        'team_id', 'competition_id', 'season_start_year', 'game_format_id', 'rules', 'opponent_id', 'venue_id',
        'side', 'round_label', 'date', 'kickoff_time', 'meeting_time', 'meeting_place', 'status',
        'our_score', 'their_score', 'period_scores', 'extra_time_played', 'shootout_us', 'shootout_them',
        'formation_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
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

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Game $game) {
            if (empty($game->rules) && $game->game_format_id) {
                $game->rules = GameFormat::findOrFail($game->game_format_id)->rulesSnapshot();
            }
        });
    }

    /** One rule of this match, from its own copy (never from the format template). */
    public function rule(string $key, mixed $default = null): mixed
    {
        return ($this->rules ?? [])[$key] ?? $default;
    }

    /** Regulation time, plus extra time when it was (or, given true, would be) played. */
    public function matchLength(?bool $withExtraTime = null): int
    {
        $withExtraTime ??= (bool) $this->extra_time_played;

        return (int) $this->rule('periods_count', 0) * (int) $this->rule('period_minutes', 0)
            + ($withExtraTime ? (int) $this->rule('extra_time_minutes', 0) : 0);
    }

    /** Sports with a goalkeeper role on the line-up. */
    public function hasGoalkeeper(): bool
    {
        return in_array($this->rule('sport'), ['football', 'futsal', 'handball'], true);
    }

    /** Whether a sanction of this kind sends the player off (ends their minutes). */
    public function endsPlay(?string $sanctionKey): bool
    {
        foreach ($this->rule('sanction_types', []) as $type) {
            if (($type['key'] ?? null) === $sanctionKey) {
                return (bool) ($type['ends_play'] ?? false);
            }
        }

        return false;
    }

    /** What a sanction of this kind counts as for bans ('yellow' / 'red'), per this match's own rules. */
    public function countsAs(?string $sanctionKey): ?string
    {
        foreach ($this->rule('sanction_types', []) as $type) {
            if (($type['key'] ?? null) === $sanctionKey) {
                return in_array($type['counts_as'] ?? null, ['yellow', 'red'], true) ? $type['counts_as'] : null;
            }
        }

        return null;
    }
}
