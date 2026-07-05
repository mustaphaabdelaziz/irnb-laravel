<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentCatalog extends Model
{
    use HasFactory;

    /** Normalise the stored picture URL to a host-relative /media path (web + desktop). */
    protected function pictureUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }

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
