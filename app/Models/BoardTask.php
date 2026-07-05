<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardTask extends Model
{
    protected $fillable = [
        'title', 'description', 'board_member_id', 'board_meeting_id', 'due_date',
        'status', 'progress', 'priority', 'completed_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'progress' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'board_member_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class, 'board_meeting_id');
    }
}
