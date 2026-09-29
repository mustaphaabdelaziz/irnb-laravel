<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How many pre-season (préparation physique) sessions a category plans for a season. */
class PreseasonTarget extends Model
{
    protected $fillable = ['category_id', 'season_start_year', 'target_count'];

    protected function casts(): array
    {
        return ['season_start_year' => 'integer', 'target_count' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
