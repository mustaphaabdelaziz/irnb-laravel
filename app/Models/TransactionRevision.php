<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edit of a transaction: the tracked fields that changed, as
 * { field: { old, new } } (see TransactionChanges). The column is `diff`
 * because Eloquent keeps its own $changes property.
 */
class TransactionRevision extends Model
{
    use SerializesClubDates;

    const UPDATED_AT = null;

    protected $fillable = ['transaction_id', 'activity_log_id', 'diff'];

    protected function casts(): array
    {
        return ['diff' => 'array'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** Who edited and when; null once that log row is gone. */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }
}
