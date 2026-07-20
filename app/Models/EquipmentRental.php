<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EquipmentRental extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_item_id',
        'rentable_type',
        'rentable_id',
        'type',
        'quantity',
        'returned_quantity',
        'checkout_date',
        'due_date',
        'return_date',
        'notes',
        'return_notes',
    ];

    protected function casts(): array
    {
        return [
            'checkout_date' => 'datetime',
            'due_date' => 'date',
            'return_date' => 'datetime',
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
        ];
    }

    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class);
    }

    public function rentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Units still out: what was taken, less what has come back. */
    public function getOutstandingQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->returned_quantity);
    }

    public function getIsAssignmentAttribute(): bool
    {
        return $this->type === 'assignment';
    }

    public function getIsOverdueAttribute(): bool
    {
        // An assignment is open-ended by definition, so it can never run late.
        if ($this->type === 'assignment') {
            return false;
        }

        if ($this->return_date || ! $this->due_date) {
            return false;
        }

        return $this->due_date->isPast();
    }

    public function getRentalDurationAttribute(): int
    {
        $end = $this->return_date ?? now();

        return $this->checkout_date->diffInDays($end);
    }
}
