<?php

namespace App\Services\Equipment;

use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EquipmentLifecycleService
{
    public function rentOut(EquipmentItem $item, Model $rentable, ?string $dueDate = null, ?int $userId = null, ?string $notes = null): EquipmentRental
    {
        if ($item->status !== 'Available') {
            throw new \InvalidArgumentException("Item #{$item->unique_identifier} is not available for rental (current status: {$item->status}).");
        }

        return DB::transaction(function () use ($item, $rentable, $dueDate, $userId, $notes) {
            $item->update(['status' => 'Rented']);

            $rental = EquipmentRental::create([
                'equipment_item_id' => $item->id,
                'rentable_type' => $rentable->getMorphClass(),
                'rentable_id' => $rentable->getKey(),
                'checkout_date' => now(),
                'due_date' => $dueDate,
                'notes' => $notes,
            ]);

            $this->logHistory($item, $userId, 'Checkout', [
                'rentable_type' => $rentable->getMorphClass(),
                'rentable_id' => $rentable->getKey(),
                'due_date' => $dueDate,
            ]);

            return $rental;
        });
    }

    public function returnItem(EquipmentRental $rental, ?int $userId = null, string $condition = 'Good', ?string $notes = null): void
    {
        $item = $rental->equipmentItem;

        if ($item->status !== 'Rented') {
            throw new \InvalidArgumentException("Item #{$item->unique_identifier} is not currently rented (current status: {$item->status}).");
        }

        DB::transaction(function () use ($item, $rental, $userId, $condition, $notes) {
            $rental->update(['return_date' => now(), 'notes' => $notes]);

            $item->update([
                'status' => 'Available',
                'condition' => $condition,
            ]);

            $this->logHistory($item, $userId, 'Return', [
                'rental_id' => $rental->id,
                'returned_condition' => $condition,
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

            // Close any active rental
            $activeRental = $item->activeRental;
            if ($activeRental) {
                $activeRental->update(['return_date' => now(), 'notes' => 'Marked as lost']);
            }

            $this->logHistory($item, $userId, 'Lost', [
                'previous_status' => $previousStatus,
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
