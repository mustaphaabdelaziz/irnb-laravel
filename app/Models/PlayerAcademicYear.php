<?php

namespace App\Models;

use App\Enums\AcademicPeriod;
use App\Enums\EducationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One school year of a student player: where they studied, and their trimester grades. */
class PlayerAcademicYear extends Model
{
    protected $fillable = ['player_id', 'academic_year', 'education_level', 'institution', 'field_of_study'];

    protected $appends = ['scale', 'average', 'is_provisional'];

    protected function casts(): array
    {
        return ['academic_year' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Trimesters of this year, T1 first. */
    public function records(): HasMany
    {
        return $this->hasMany(PlayerAcademicRecord::class)
            ->orderByRaw(AcademicPeriod::rankSql('period'))
            ->orderBy('id');
    }

    public function level(): EducationLevel
    {
        return EducationLevel::from($this->education_level);
    }

    public function scale(): int
    {
        return $this->level()->scale();
    }

    /** Mean of the trimesters entered so far, or null when none. */
    public function average(): ?float
    {
        $grades = $this->records->map(fn (PlayerAcademicRecord $r) => (float) $r->gpa);

        return $grades->isEmpty() ? null : round($grades->avg(), 2);
    }

    /** The average is final only once all three trimesters are in. */
    public function isProvisional(): bool
    {
        return $this->records->count() < count(AcademicPeriod::cases());
    }

    public function getScaleAttribute(): int
    {
        return $this->scale();
    }

    public function getAverageAttribute(): ?float
    {
        return $this->average();
    }

    public function getIsProvisionalAttribute(): bool
    {
        return $this->isProvisional();
    }
}
