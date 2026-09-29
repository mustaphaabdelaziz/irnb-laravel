<?php

namespace App\Models;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One training on one date. `date`/`moved_from` are 'Y-m-d' strings and times
 * 'H:i' strings (see the create_attendance_tables migration). `category_id` is
 * the primary category; `categories()` holds every category taking part (only
 * pre-season sessions have more than one).
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

    protected static function booted(): void
    {
        // Every session is in the pivot with its primary category, so lists,
        // rosters and slot checks can read the pivot alone. (The generator
        // inserts rows without models and fills the pivot itself.)
        static::created(function (TrainingSession $session) {
            DB::table('training_session_category')->insertOrIgnore([
                'training_session_id' => $session->id,
                'category_id' => $session->category_id,
            ]);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'training_session_category');
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

    /** Sessions the category takes part in: its own and joint pre-season ones. */
    public function scopeIncludingCategory(Builder $query, int $categoryId): void
    {
        $query->whereIn('training_sessions.id', DB::table('training_session_category')
            ->select('training_session_id')
            ->where('category_id', $categoryId));
    }

    /** @return array<int, int> sorted ids of every category in the session */
    public function categoryIds(): array
    {
        $ids = $this->relationLoaded('categories')
            ? $this->categories->modelKeys()
            : $this->categories()->pluck('categories.id')->all();
        $ids = array_map('intval', $ids !== [] ? $ids : [$this->category_id]);
        sort($ids);

        return $ids;
    }

    /** @return Collection<int, Category> every category taking part, primary first then by id */
    public function orderedCategories(): Collection
    {
        if (! $this->relationLoaded('categories')) {
            $this->load('categories');
        }

        return $this->categories
            ->sortBy(fn (Category $c) => $c->id === $this->category_id ? 0 : $c->id)
            ->values();
    }
}
