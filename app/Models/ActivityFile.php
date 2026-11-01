<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An attached document on the private disk; reached only through ActivityFileController::show. */
class ActivityFile extends Model
{
    use SerializesClubDates;

    protected $fillable = ['activity_id', 'path', 'original_name', 'mime', 'size'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
