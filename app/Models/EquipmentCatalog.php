<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentCatalog extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'brand',
        'description',
        'specifications',
        'purchase_price',
        'picture_url',
        'picture_filename',
        'item_count',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array',
            'purchase_price' => 'decimal:2',
            'item_count' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EquipmentItem::class, 'catalog_id');
    }

    public function getAvailableCountAttribute(): int
    {
        return $this->items()->where('status', 'Available')->count();
    }
}
