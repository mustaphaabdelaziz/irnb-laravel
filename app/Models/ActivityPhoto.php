<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A gallery photo: 1600px `path` + 400px `thumb_path` on the public disk. */
class ActivityPhoto extends Model
{
    use SerializesClubDates;

    /** Photos per activity printed in the literary report. */
    public const REPORT_LIMIT = 4;

    protected $fillable = ['activity_id', 'path', 'thumb_path', 'caption', 'in_report', 'sort_order'];

    protected $appends = ['url', 'thumb_url'];

    protected $hidden = ['path', 'thumb_path'];

    protected function casts(): array
    {
        return ['in_report' => 'boolean'];
    }

    protected function url(): Attribute
    {
        return Attribute::make(get: fn () => '/media/'.ltrim((string) $this->path, '/'));
    }

    protected function thumbUrl(): Attribute
    {
        return Attribute::make(get: fn () => '/media/'.ltrim((string) $this->thumb_path, '/'));
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
