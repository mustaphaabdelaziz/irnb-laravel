<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAttendance extends Model
{
    protected $fillable = ['board_meeting_id', 'board_member_id', 'status'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class, 'board_meeting_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'board_member_id');
    }
}
