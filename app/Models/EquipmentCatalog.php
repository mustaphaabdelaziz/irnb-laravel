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
        'requires_serial',
        'brand',
        'description',
        'specifications',
        'purchase_price',
        'picture_url',
        'picture_filename',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array',
            'purchase_price' => 'decimal:2',
            'requires_serial' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EquipmentItem::class, 'catalog_id');
    }

    /** Every unit held under this catalog, across all its lots. */
    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    /**
     * Units that can be issued right now. Keeps its old name so existing
     * bindings still work; only its meaning changes from rows to units.
     *
     * Loading the lots to sum a derived value is fine here — a catalog holds
     * a handful of lots, and under the lot model that is fewer rows than
     * before, not more.
     */
    public function getAvailableCountAttribute(): int
    {
        return $this->items()->with('rentals')->get()
            ->sum(fn (EquipmentItem $item) => $item->available_quantity);
    }
}
