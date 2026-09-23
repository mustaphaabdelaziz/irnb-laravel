<?php

namespace App\Models;

use App\Enums\AcademicPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One graded trimester of a student player, out of 20. */
class PlayerAcademicRecord extends Model
{
    public const PASS_MARK = 10;

    protected $fillable = [
        'player_id',
        'academic_year',
        'period',
        'gpa',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'academic_year' => 'integer',
            'gpa' => 'decimal:2',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Oldest first: by school year, then by period rank. */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('academic_year')
            ->orderByRaw(AcademicPeriod::rankSql('period'))
            ->orderBy('id');
    }

    /**
     * Correlated subquery yielding a player's most recent GPA (NULL when none).
     * The one definition of "latest" for list filters and dashboard stats.
     */
    public static function latestGpaSql(string $playerIdColumn = 'players.id'): string
    {
        $rank = AcademicPeriod::rankSql('par.period');

        return 'SELECT par.gpa FROM player_academic_records par'
            ." WHERE par.player_id = {$playerIdColumn}"
            ." ORDER BY par.academic_year DESC, {$rank} DESC, par.id DESC LIMIT 1";
    }
}
