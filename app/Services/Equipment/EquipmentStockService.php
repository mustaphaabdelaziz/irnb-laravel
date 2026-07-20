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
     * Move units out of a lot into a new lot with a different condition —
     * "3 of these 20 balls are punctured".
     *
     * Atomic: the two rows must never disagree about the total, which is why
     * this is a service method rather than two separate updates.
     */
    public function splitLot(EquipmentItem $item, int $quantity, string $condition, ?int $userId = null, ?string $notes = null): EquipmentItem
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Split quantity must be at least 1.');
        }

        $available = $this->availableQuantity($item);

        if ($quantity > $available) {
            // Units out on rental are not in your hands to inspect.
            throw new \InvalidArgumentException("Cannot split {$quantity} units: only {$available} are available in this lot.");
        }

        return DB::transaction(function () use ($item, $quantity, $condition, $userId, $notes) {
            $item->decrement('quantity', $quantity);

            $new = EquipmentItem::create([
                'catalog_id' => $item->catalog_id,
                'quantity' => $quantity,
                'unit_price' => $item->unit_price,
                'purchase_date' => $item->purchase_date,
                'condition' => $condition,
                'location' => $item->location,
                'status' => 'Available',
                // Split units keep the origin of the batch they came from.
                // Coalesced because an in-memory parent may not have the
                // column's database default loaded.
                'received_via' => $item->received_via ?? 'purchase',
                'notes' => $notes,
            ]);

            $new->branches()->sync($item->branches()->pluck('branches.id')->all());

            $details = [
                'quantity' => $quantity,
                'condition' => $condition,
                'notes' => $notes,
            ];

            EquipmentHistory::create([
                'item_id' => $item->id,
                'user_id' => $userId,
                'event_type' => 'Split Out',
                'details' => $details + ['to_item_id' => $new->id],
                'event_timestamp' => now(),
            ]);

            EquipmentHistory::create([
                'item_id' => $new->id,
                'user_id' => $userId,
                'event_type' => 'Split In',
                'details' => $details + ['from_item_id' => $item->id],
                'event_timestamp' => now(),
            ]);

            return $new;
        });
    }

    /**
     * Write off units that a stock-take could not find.
     *
     * Finding 47 of 50 dossards must not condemn all 50: the three missing
     * units move into their own lot marked Lost and the rest stay in service.
     * If nothing at all was found the whole lot is marked Lost, which is what
     * a serialized item does.
     */
    public function writeOffMissing(EquipmentItem $item, int $quantity, ?int $userId = null): void
    {
        if ($quantity < 1) {
            return;
        }

        if ($quantity >= $item->quantity) {
            $item->update(['status' => 'Lost']);

            EquipmentHistory::create([
                'item_id' => $item->id,
                'user_id' => $userId,
                'event_type' => 'Lost',
                'details' => ['quantity' => $item->quantity, 'source' => 'stocktake'],
                'event_timestamp' => now(),
            ]);

            return;
        }

        DB::transaction(function () use ($item, $quantity, $userId) {
            $item->decrement('quantity', $quantity);

            $lost = EquipmentItem::create([
                'catalog_id' => $item->catalog_id,
                'quantity' => $quantity,
                'unit_price' => $item->unit_price,
                'purchase_date' => $item->purchase_date,
                // Coalesced because an in-memory parent may not carry the
                // column's database default.
                'condition' => $item->condition ?? 'New',
                'location' => $item->location,
                'status' => 'Lost',
                'received_via' => $item->received_via ?? 'purchase',
            ]);

            $lost->branches()->sync($item->branches()->pluck('branches.id')->all());

            EquipmentHistory::create([
                'item_id' => $lost->id,
                'user_id' => $userId,
                'event_type' => 'Lost',
                'details' => ['quantity' => $quantity, 'from_item_id' => $item->id, 'source' => 'stocktake'],
                'event_timestamp' => now(),
            ]);
        });
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
