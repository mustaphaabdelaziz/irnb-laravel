<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A club team: a sport (branch), an age category and a name ("Cadets A").
 * Permanent; its roster and coaches are kept per season. A player of another
 * category may be on the roster (playing up).
 */
class Team extends Model
{
    protected $fillable = ['branch_id', 'category_id', 'name', 'default_game_format_id', 'archived'];

    protected function casts(): array
    {
        return ['archived' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function defaultFormat(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'default_game_format_id');
    }

    /** Every season's roster rows; use rosterFor() for one season. */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'team_players')
            ->withPivot(['season_start_year', 'shirt_number'])->withTimestamps();
    }

    public function rosterFor(int $season): BelongsToMany
    {
        return $this->players()->wherePivot('season_start_year', $season);
    }

    public function coaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_coaches')
            ->withPivot(['season_start_year', 'role'])->withTimestamps();
    }

    public function coachesFor(int $season): BelongsToMany
    {
        return $this->coaches()->wherePivot('season_start_year', $season);
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
