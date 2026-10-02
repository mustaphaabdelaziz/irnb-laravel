<?php

namespace App\Models;

use App\Enums\AbsenceReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's mark at one training session. `status` is a status key (see
 * AttendanceStatusCatalog): a built-in AttendanceStatus value or a custom
 * code's key, so it stays a plain string.
 */
class Attendance extends Model
{
    protected $fillable = ['training_session_id', 'player_id', 'category_id', 'status', 'minutes', 'reason', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['reason' => AbsenceReason::class, 'minutes' => 'integer'];
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
