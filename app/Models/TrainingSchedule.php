<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One weekly training slot of a category, valid over a date range. */
class TrainingSchedule extends Model
{
    protected $fillable = ['category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
