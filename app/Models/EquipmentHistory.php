<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentHistory extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'item_id',
        'user_id',
        'event_type',
        'details',
        'event_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'event_timestamp' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
