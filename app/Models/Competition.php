<?php

namespace App\Models;

use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One team's entry in a league, cup, tournament or friendly series for a
 * season. Its squad (competition_players) is picked from the team's roster;
 * a `hidden` squad row is a player removed after already playing in it.
 */
class Competition extends Model
{
    protected $fillable = [
        'team_id', 'season_start_year', 'name', 'type', 'game_format_id',
        'red_ban_matches', 'yellow_threshold', 'yellow_ban_matches', 'archived', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => CompetitionType::class, 'archived' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'game_format_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'competition_players')->withPivot('hidden')->withTimestamps();
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
