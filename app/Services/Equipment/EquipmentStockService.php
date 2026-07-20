<?php

namespace App\Services\Equipment;

use App\Models\EquipmentItem;

/**
 * All lot arithmetic lives here.
 *
 * A row in equipment_items is a LOT of N units. Availability is never read
 * from the `status` column — a lot of 20 cannot be described by one status
 * when 10 of its units are out — so it is always derived from the open
 * rentals against that lot.
 */
class EquipmentStockService
{
    /** Statuses that make an entire lot unavailable whatever the rentals say. */
    public const BLOCKING_STATUSES = ['Under Repair', 'Lost', 'Retired', 'Out of Service'];

    /**
     * Units of this lot that can be issued right now.
     *
     * For a single-unit lot this reduces exactly to the historical behaviour:
     * a rented item has one open rental, so 1 - 1 = 0.
     */
    public function availableQuantity(EquipmentItem $item): int
    {
        if (in_array($item->status, self::BLOCKING_STATUSES, true)) {
            return 0;
        }

        return max(0, $item->quantity - $this->outstandingQuantity($item));
    }

    /** Units currently out on open rentals or assignments. */
    public function outstandingQuantity(EquipmentItem $item): int
    {
        return (int) $item->rentals()
            ->whereNull('return_date')
            ->selectRaw('COALESCE(SUM(quantity - returned_quantity), 0) as outstanding')
            ->value('outstanding');
    }
}
