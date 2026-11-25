<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ordered item: an equipment catalog item or free text; price optional until a payment order is made. */
class PurchaseOrderLine extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'purchase_order_id', 'equipment_catalog_id', 'designation', 'quantity', 'unit_price', 'amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** quantity × unit price, or null while the line has no price. */
    public static function amountFor(int $quantity, mixed $unitPrice): ?float
    {
        if ($unitPrice === null || $unitPrice === '') {
            return null;
        }

        return round($quantity * (float) $unitPrice, 2);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(EquipmentCatalog::class, 'equipment_catalog_id');
    }
}
