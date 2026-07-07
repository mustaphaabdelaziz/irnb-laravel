<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventorySessionParticipant extends Model
{
    protected $fillable = ['inventory_session_id', 'participant_type', 'participant_id'];

    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class, 'inventory_session_id');
    }
}
