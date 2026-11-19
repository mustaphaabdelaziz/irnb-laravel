<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySessionItem extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'inventory_session_id', 'equipment_item_id', 'expected_status',
        'expected_condition', 'expected_location', 'expected_quantity', 'on_loan_quantity',
        'counted', 'found', 'found_quantity',
        'actual_condition', 'actual_location', 'note',
    ];

    protected $appends = ['found_units'];

    protected function casts(): array
    {
        return [
            'counted' => 'boolean',
            'found' => 'boolean',
            'expected_quantity' => 'integer',
            'on_loan_quantity' => 'integer',
            'found_quantity' => 'integer',
        ];
    }

    /** Units expected but not accounted for. An uncounted line is all missing. */
    public function getMissingQuantityAttribute(): int
    {
        return max(0, $this->expected_quantity - (int) $this->found_quantity);
    }

    /**
     * Units found on this line, the single rule everywhere: 0 when not counted
     * or not found; a found line with no quantity (saved before quantities
     * existed) means every expected unit turned up.
     */
    public function getFoundUnitsAttribute(): int
    {
        if (! $this->counted || ! $this->found) {
            return 0;
        }

        return (int) ($this->found_quantity ?? $this->expected_quantity);
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
