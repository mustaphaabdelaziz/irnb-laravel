<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentLotRentalTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600000;

    private function lot(int $quantity = 20): EquipmentItem
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
        ]);
    }

    /** There is no PlayerFactory in this project; tests build players directly. */
    private function player(): Player
    {
        return Player::create([
            'firstname' => 'Ali',
            'lastname' => 'B',
            'membership_id' => (string) ++$this->membership,
            'join_year' => 2026,
        ]);
    }

    #[Test]
    public function a_rental_defaults_to_one_unit_of_type_rental(): void
    {
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
        ]);

        $rental = $rental->fresh();
        $this->assertSame('rental', $rental->type);
        $this->assertSame(1, $rental->quantity);
        $this->assertSame(0, $rental->returned_quantity);
    }

    #[Test]
    public function outstanding_quantity_accounts_for_partial_returns(): void
    {
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
            'quantity' => 10,
            'returned_quantity' => 6,
        ]);

        $this->assertSame(4, $rental->fresh()->outstanding_quantity);
    }

    #[Test]
    public function an_assignment_is_never_overdue(): void
    {
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now()->subYear(),
            'due_date' => now()->subMonths(6),
            'type' => 'assignment',
        ]);

        $this->assertFalse($rental->fresh()->is_overdue);
    }

    #[Test]
    public function an_overdue_rental_is_still_reported_as_overdue(): void
    {
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now()->subYear(),
            'due_date' => now()->subMonths(6),
            'type' => 'rental',
        ]);

        $this->assertTrue($rental->fresh()->is_overdue);
    }
}
