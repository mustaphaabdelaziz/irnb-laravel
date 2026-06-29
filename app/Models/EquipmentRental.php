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
        'checkout_date',
        'due_date',
        'return_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'checkout_date' => 'datetime',
            'due_date' => 'date',
            'return_date' => 'datetime',
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

    public function getIsOverdueAttribute(): bool
    {
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
