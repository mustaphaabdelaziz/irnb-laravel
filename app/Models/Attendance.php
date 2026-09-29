<?php

namespace App\Models;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One player's mark at one training session. */
class Attendance extends Model
{
    protected $fillable = ['training_session_id', 'player_id', 'category_id', 'status', 'minutes', 'reason', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['status' => AttendanceStatus::class, 'reason' => AbsenceReason::class, 'minutes' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** The player's category when this mark was recorded — fixed, never the player's current one. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
