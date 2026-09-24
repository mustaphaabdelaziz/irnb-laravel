<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardMeeting extends Model
{
    protected $fillable = [
        'title', 'type', 'meeting_date', 'location', 'agenda', 'status',
        'quorum_required', 'minutes', 'decisions', 'attachment_url',
        'attachment_filename', 'created_by_user_id',
        'cancelled_at', 'cancelled_by_user_id', 'cancel_reason',
    ];

    /**
     * Where the page links the minutes file. A stored file is private and only
     * reachable through the authenticated route (host-relative, so it works on
     * the web and in the desktop window). A legacy external link is kept as is.
     */
    protected function attachmentUrl(): Attribute
    {
        return Attribute::make(get: function ($value, array $attributes) {
            if (! empty($attributes['attachment_filename']) && ! empty($attributes['id'])) {
                return route('board.meetings.attachment.show', $attributes['id'], false);
            }

            return Media::path($value);
        });
    }

    protected function casts(): array
    {
        return [
            'meeting_date' => 'datetime',
            'cancelled_at' => 'datetime',
            'agenda' => 'array',
            'decisions' => 'array',
            'quorum_required' => 'integer',
        ];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BoardTask::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** A cancelled meeting is kept as history and can no longer be changed. */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
