<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Services\Equipment\EquipmentLifecycleService;
use App\Services\Equipment\EquipmentStockService;
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
    public function ten_units_can_be_issued_from_a_lot_of_twenty(): void
    {
        $lot = $this->lot(20);

        app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 10,
            'checkout_date' => '2026-03-01',
            'due_date' => '2026-03-31',
        ]);

        $this->assertSame(10, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
        $this->assertSame('Available', $lot->fresh()->status, 'a multi-unit lot keeps its status');
    }

    #[Test]
    public function a_single_unit_lot_still_flips_to_rented(): void
    {
        $lot = $this->lot(1);

        app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 1]);

        $this->assertSame('Rented', $lot->fresh()->status);
    }

    #[Test]
    public function issuing_more_than_available_is_rejected(): void
    {
        $lot = $this->lot(20);

        $this->expectException(\InvalidArgumentException::class);

        app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 25]);
    }

    #[Test]
    public function the_checkout_date_can_be_backdated(): void
    {
        $lot = $this->lot(20);

        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 5,
            'checkout_date' => '2026-03-01',
        ]);

        $this->assertSame('2026-03-01', $rental->fresh()->checkout_date->toDateString());
    }

    #[Test]
    public function a_partial_return_leaves_the_rest_outstanding(): void
    {
        $lot = $this->lot(20);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 10]);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 6, 'condition' => 'Good']);

        $rental = $rental->fresh();
        $this->assertSame(6, $rental->returned_quantity);
        $this->assertNull($rental->return_date, 'the rental stays open until every unit is back');
        $this->assertSame(16, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function returning_the_last_unit_closes_the_rental(): void
    {
        $lot = $this->lot(20);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 10]);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 6]);
        app(EquipmentLifecycleService::class)->returnItem($rental->fresh(), ['quantity' => 4]);

        $this->assertNotNull($rental->fresh()->return_date);
        $this->assertSame(20, app(EquipmentStockService::class)->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function returning_more_than_is_outstanding_is_rejected(): void
    {
        $lot = $this->lot(20);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), ['quantity' => 10]);

        $this->expectException(\InvalidArgumentException::class);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 11]);
    }

    #[Test]
    public function the_checkout_note_survives_a_return(): void
    {
        $lot = $this->lot(1);
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 1,
            'notes' => 'handed over at training',
        ]);

        app(EquipmentLifecycleService::class)->returnItem($rental, ['quantity' => 1, 'notes' => 'returned dirty']);

        $rental = $rental->fresh();
        $this->assertSame('handed over at training', $rental->notes);
        $this->assertSame('returned dirty', $rental->return_notes);
    }

    #[Test]
    public function an_assignment_never_stores_a_due_date(): void
    {
        $lot = $this->lot(5);

        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 1,
            'type' => 'assignment',
            'due_date' => '2026-12-31',
        ]);

        $this->assertNull($rental->fresh()->due_date, 'an assignment is open-ended');
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
