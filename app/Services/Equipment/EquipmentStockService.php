<?php

namespace App\Services\Equipment;

use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Bring stock into the club as a new lot.
     *
     * Spending money is opt-in via `record_expense`, not a side effect of
     * recording what the equipment is worth. Unticked covers donations,
     * found items and opening balances.
     */
    public function receive(array $data, ?int $userId = null): EquipmentItem
    {
        return DB::transaction(function () use ($data, $userId) {
            $quantity = (int) $data['quantity'];
            $unitPrice = isset($data['unit_price']) && $data['unit_price'] !== null
                ? (float) $data['unit_price']
                : null;
            $purchaseDate = $data['purchase_date'];

            $transactionId = null;

            if (! empty($data['record_expense']) && $unitPrice > 0) {
                $transactionId = Transaction::create([
                    'amount' => $unitPrice * $quantity,
                    'transaction_date' => $purchaseDate,
                    'transaction_type' => 'expense',
                    'category' => 'equipment',
                    'description' => "Equipment purchase: {$quantity} unit(s)",
                    'recorded_by_user_id' => $userId,
                    'status' => 'Paid',
                    // The purchase date's year, not today's — a backdated
                    // purchase belongs to the year it happened.
                    'fiscal_year' => Carbon::parse($purchaseDate)->year,
                ])->id;
            }

            $item = EquipmentItem::create([
                'catalog_id' => $data['catalog_id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'purchase_date' => $purchaseDate,
                'condition' => $data['condition'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_via' => $data['received_via'] ?? 'purchase',
                'purchase_transaction_id' => $transactionId,
                'status' => 'Available',
            ]);

            if (! empty($data['branch_ids'])) {
                $item->branches()->sync($data['branch_ids']);
            }

            EquipmentHistory::create([
                'item_id' => $item->id,
                'user_id' => $userId,
                'event_type' => 'Received',
                'details' => [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'received_via' => $item->received_via,
                    'transaction_id' => $transactionId,
                ],
                'event_timestamp' => now(),
            ]);

            return $item;
        });
    }
}
