<?php

namespace App\Models;

use App\Enums\AcademicPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One graded trimester of a school year, on that year's scale (/10 primary, /20 otherwise). */
class PlayerAcademicRecord extends Model
{
    /** Pass mark once a grade is converted to /20 (latestOn20Sql's scale). Half of 20. */
    public const PASS_MARK_ON_20 = 10;

    protected $fillable = ['player_academic_year_id', 'period', 'gpa', 'certificate', 'remark'];

    protected function casts(): array
    {
        return ['gpa' => 'decimal:2'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(PlayerAcademicYear::class, 'player_academic_year_id');
    }

    /**
     * Correlated subquery: a player's most recent trimester grade converted to /20
     * (NULL when none). The one definition of "latest" for list filters and dashboard stats.
     */
    public static function latestOn20Sql(string $playerIdColumn = 'players.id'): string
    {
        $rank = AcademicPeriod::rankSql('par.period');

        return "SELECT par.gpa * 20.0 / (CASE pay.education_level WHEN 'primary' THEN 10 ELSE 20 END)"
            .' FROM player_academic_records par'
            .' JOIN player_academic_years pay ON pay.id = par.player_academic_year_id'
            ." WHERE pay.player_id = {$playerIdColumn}"
            ." ORDER BY pay.academic_year DESC, {$rank} DESC, par.id DESC LIMIT 1";
    }
}
