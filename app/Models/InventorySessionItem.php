<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySessionItem extends Model
{
    protected $fillable = [
        'inventory_session_id', 'equipment_item_id', 'expected_status',
        'expected_condition', 'expected_location', 'counted', 'found',
        'actual_condition', 'actual_location', 'note',
    ];

    protected function casts(): array
    {
        return [
            'counted' => 'boolean',
            'found' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class, 'inventory_session_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }
}
