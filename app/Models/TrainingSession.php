<?php

namespace App\Models;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One training of one category on one date. `date`/`moved_from` are 'Y-m-d'
 * strings and times 'H:i' strings (see the create_attendance_tables migration).
 */
class TrainingSession extends Model
{
    protected $fillable = [
        'category_id', 'schedule_id', 'date', 'start_time', 'end_time', 'kind', 'state',
        'cancel_reason', 'moved_from', 'coach', 'title', 'notes',
    ];

    protected function casts(): array
    {
        return ['kind' => SessionKind::class, 'state' => SessionState::class];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** Planned sessions nobody has marked yet: safe to delete and regenerate. */
    public function scopeUnmarkedPlanned(Builder $query): void
    {
        $query->where('state', SessionState::Planned->value)->whereDoesntHave('attendances');
    }
}
