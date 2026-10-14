<?php

namespace App\Models;

use App\Enums\PeriodLabel;
use App\Enums\ScoringType;
use App\Enums\Sport;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sport's game rules (football 11, handball, volleyball…): players on the
 * field, bench, periods, scoring, sanctions. Presets ship with the app and
 * stay editable. A team plays its default format; a competition may override it.
 */
class GameFormat extends Model
{
    use HasLocalizedName;

    protected $fillable = [
        'branch_id', 'sport', 'name', 'name_ar', 'name_fr', 'name_en',
        'players_on_field', 'max_bench', 'max_called_up', 'max_substitutions',
        'periods_count', 'period_minutes', 'period_label', 'scoring', 'sets_to_win',
        'has_extra_time', 'extra_time_minutes', 'has_shootout', 'rolling_subs', 'sanction_types', 'is_preset', 'active',
    ];

    protected $appends = ['localized_name'];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'period_label' => PeriodLabel::class,
            'scoring' => ScoringType::class,
            'sanction_types' => 'array',
            'has_extra_time' => 'boolean',
            'has_shootout' => 'boolean',
            'rolling_subs' => 'boolean',
            'max_substitutions' => 'integer',
            'extra_time_minutes' => 'integer',
            'is_preset' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function formations(): HasMany
    {
        return $this->hasMany(Formation::class);
    }

    /** Whether a team, competition or game uses it — such a format can be edited but not deleted. */
    public function inUse(): bool
    {
        return Team::where('default_game_format_id', $this->id)->exists()
            || Competition::where('game_format_id', $this->id)->exists()
            || Game::where('game_format_id', $this->id)->exists();
    }

    /** The rules a new match copies onto itself (games.rules), so later edits here never rewrite it. */
    public function rulesSnapshot(): array
    {
        return [
            'sport' => $this->sport->value,
            'players_on_field' => $this->players_on_field,
            'max_bench' => $this->max_bench,
            'max_called_up' => $this->max_called_up,
            'max_substitutions' => $this->max_substitutions,
            'periods_count' => $this->periods_count,
            'period_minutes' => $this->period_minutes,
            'period_label' => $this->period_label->value,
            'scoring' => $this->scoring->value,
            'sets_to_win' => $this->sets_to_win,
            'has_extra_time' => $this->has_extra_time,
            'extra_time_minutes' => $this->extra_time_minutes,
            'has_shootout' => $this->has_shootout,
            'rolling_subs' => $this->rolling_subs,
            'sanction_types' => $this->sanction_types ?? [],
        ];
    }
}
