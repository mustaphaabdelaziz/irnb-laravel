<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EquipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_id',
        'unique_identifier',
        'purchase_date',
        'status',
        'condition',
        'location',
        'purchase_transaction_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(EquipmentCatalog::class, 'catalog_id');
    }

    public function purchaseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'purchase_transaction_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(EquipmentRental::class);
    }

    public function activeRental(): HasOne
    {
        return $this->hasOne(EquipmentRental::class)->whereNull('return_date')->latest('checkout_date');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(EquipmentHistory::class, 'item_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'Rented'
            && $this->activeRental?->is_overdue === true;
    }

    public function getRentalDurationAttribute(): int
    {
        return $this->activeRental?->rental_duration ?? 0;
    }
}
