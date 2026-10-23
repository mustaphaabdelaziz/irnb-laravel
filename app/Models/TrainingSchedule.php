<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Services\Attendance\GenerationMarks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One weekly training slot of a branch and category, valid over a date range. */
class TrainingSchedule extends Model
{
    use SerializesClubDates;

    protected $fillable = ['branch_id', 'category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    protected static function booted(): void
    {
        // Any change can add or free slots in any month of the category it
        // belonged to and the one it belongs to now: generate those again.
        static::saved(fn (TrainingSchedule $schedule) => GenerationMarks::forgetCategories(
            (int) $schedule->category_id,
            (int) ($schedule->getOriginal('category_id') ?? $schedule->category_id),
        ));
        static::deleted(fn (TrainingSchedule $schedule) => GenerationMarks::forgetCategories((int) $schedule->category_id));
    }

    /** The branch whose sessions this slot generates; null = the whole club. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
