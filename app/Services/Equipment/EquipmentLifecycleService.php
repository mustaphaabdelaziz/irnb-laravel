<?php

namespace App\Services\Equipment;

use App\Exceptions\Equipment\StockException;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EquipmentLifecycleService
{
    /**
     * Issue units of a lot to a player or a user.
     *
     * $options: quantity, type ('rental'|'assignment'), checkout_date,
     * due_date, notes, user_id.
     */
    /**
     * Issue units to a team player ($rentable) or to an external person
     * (options: external_name, external_phone, with $rentable null).
     */
    public function rentOut(EquipmentItem $item, ?Model $rentable, array $options = []): EquipmentRental
    {
        $quantity = (int) ($options['quantity'] ?? 1);
        $type = $options['type'] ?? 'rental';

        if ($quantity < 1) {
            throw StockException::invalidQuantity();
        }

        $available = app(EquipmentStockService::class)->availableQuantity($item);

        if ($quantity > $available) {
            throw StockException::notEnoughAvailable($quantity, $available);
        }

        return DB::transaction(function () use ($item, $rentable, $options, $quantity, $type) {
            // status is a lot-level disposition. Only a single-unit lot flips
            // to Rented; a multi-unit lot stays Available and lets the
            // availability formula account for what is out.
            if ($item->quantity === 1) {
                $item->update(['status' => 'Rented']);
            }

            $rental = EquipmentRental::create([
                'equipment_item_id' => $item->id,
                'rentable_type' => $rentable?->getMorphClass(),
                'rentable_id' => $rentable?->getKey(),
                'external_name' => $rentable ? null : ($options['external_name'] ?? null),
                'external_phone' => $rentable ? null : ($options['external_phone'] ?? null),
                'type' => $type,
                'quantity' => $quantity,
                'returned_quantity' => 0,
                'checkout_date' => $options['checkout_date'] ?? now(),
                // An assignment is open-ended, so a due date would only be
                // stored and then silently ignored.
                'due_date' => $type === 'assignment' ? null : ($options['due_date'] ?? null),
                'notes' => $options['notes'] ?? null,
            ]);

            // So recipient_name resolves the player name without a lazy load.
            if ($rentable) {
                $rental->setRelation('rentable', $rentable);
            }

            $this->logHistory($item, $options['user_id'] ?? null, $type === 'assignment' ? 'Assigned' : 'Checkout', [
                'recipient' => $rental->recipient_name,
                'quantity' => $quantity,
                'due_date' => $rental->due_date?->toDateString(),
            ]);

            return $rental;
        });
    }

    /**
     * Take units back, in whole or in part.
     *
     * $options: quantity (defaults to everything outstanding), condition,
     * return_date, notes, user_id.
     */
    public function returnItem(EquipmentRental $rental, array $options = []): void
    {
        $item = $rental->equipmentItem;
        $outstanding = $rental->outstanding_quantity;
        $quantity = (int) ($options['quantity'] ?? $outstanding);
        $condition = $options['condition'] ?? $item->condition;

        if ($rental->return_date !== null) {
            throw StockException::rentalAlreadyClosed();
        }

        if ($quantity < 1 || $quantity > $outstanding) {
            throw StockException::tooManyToReturn($quantity, $outstanding);
        }

        DB::transaction(function () use ($item, $rental, $options, $quantity, $condition) {
            $returned = $rental->returned_quantity + $quantity;
            $fullyReturned = $returned >= $rental->quantity;

            $rental->update([
                'returned_quantity' => $returned,
                // The rental closes only once every unit is back.
                'return_date' => $fullyReturned ? ($options['return_date'] ?? now()) : null,
                // Its own column, so the checkout note survives.
                'return_notes' => $options['notes'] ?? $rental->return_notes,
            ]);

            // Only a single-unit lot carries the returned condition back to
            // the item. For a lot of 20, "6 came back damaged" is a split,
            // not a mutation of all twenty.
            if ($item->quantity === 1 && $fullyReturned) {
                $item->update(['status' => 'Available', 'condition' => $condition]);
            }

            $this->logHistory($item, $options['user_id'] ?? null, 'Return', [
                'rental_id' => $rental->id,
                'quantity' => $quantity,
                'returned_condition' => $condition,
                'fully_returned' => $fullyReturned,
                'rental_duration_days' => $rental->rental_duration,
            ]);
        });
    }

    public function sendToRepair(EquipmentItem $item, ?int $userId = null, ?string $notes = null): void
    {
        if (! in_array($item->status, ['Available', 'Rented'])) {
            throw new \InvalidArgumentException("Item #{$item->unique_identifier} cannot be sent to repair (current status: {$item->status}).");
        }

        DB::transaction(function () use ($item, $userId, $notes) {
            $previousStatus = $item->status;
            $item->update(['status' => 'Under Repair']);

            $this->logHistory($item, $userId, 'Repair', [
                'previous_status' => $previousStatus,
                'notes' => $notes,
            ]);
        });
    }

    public function completeRepair(EquipmentItem $item, ?int $userId = null, string $condition = 'Good'): void
    {
        if ($item->status !== 'Under Repair') {
            throw new \InvalidArgumentException("Item #{$item->unique_identifier} is not under repair.");
        }

        DB::transaction(function () use ($item, $userId, $condition) {
            $item->update([
                'status' => 'Available',
                'condition' => $condition,
            ]);

            $this->logHistory($item, $userId, 'Repair Complete', [
                'condition_after' => $condition,
            ]);
        });
    }

    public function markAsLost(EquipmentItem $item, ?int $userId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($item, $userId, $notes) {
            $previousStatus = $item->status;
            $item->update(['status' => 'Lost']);

            // Close any active rental. returned_quantity is squared up with
            // quantity so the availability formula stays consistent — the
            // units are gone, not outstanding.
            $activeRental = $item->activeRental;
            if ($activeRental) {
                $activeRental->update([
                    'return_date' => now(),
                    'returned_quantity' => $activeRental->quantity,
                    'return_notes' => 'Marked as lost',
                ]);
            }

            $this->logHistory($item, $userId, 'Lost', [
                'previous_status' => $previousStatus,
                'notes' => $notes,
            ]);
        });
    }

    public function markAsFound(EquipmentItem $item, ?int $userId = null, ?string $notes = null): void
    {
        if ($item->status !== 'Lost') {
            throw new \InvalidArgumentException("Item #{$item->unique_identifier} is not lost (current status: {$item->status}).");
        }

        DB::transaction(function () use ($item, $userId, $notes) {
            $item->update(['status' => 'Available']);

            $this->logHistory($item, $userId, 'Found', [
                'previous_status' => 'Lost',
                'notes' => $notes,
            ]);
        });
    }

    public function retire(EquipmentItem $item, ?int $userId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($item, $userId, $notes) {
            $previousStatus = $item->status;
            $item->update(['status' => 'Retired']);

            $this->logHistory($item, $userId, 'Retired', [
                'previous_status' => $previousStatus,
                'notes' => $notes,
            ]);
        });
    }

    private function logHistory(EquipmentItem $item, ?int $userId, string $eventType, array $details = []): void
    {
        EquipmentHistory::create([
            'item_id' => $item->id,
            'user_id' => $userId,
            'event_type' => $eventType,
            'details' => $details,
            'event_timestamp' => now(),
        ]);
    }
}
