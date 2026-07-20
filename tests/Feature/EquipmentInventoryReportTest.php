<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inventory report used to count rows. One row can now hold 100
 * dossards, so every figure must be measured in units — and "rented"
 * can no longer come from the status column, because a lot of 20 with
 * 10 units issued is still status Available.
 */
class EquipmentInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600000;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(): Player
    {
        return Player::create([
            'firstname' => 'Ali',
            'lastname' => 'B',
            'membership_id' => (string) ++$this->membership,
            'join_year' => 2026,
        ]);
    }

    private function lot(int $quantity, array $attributes = []): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(
            ['name' => 'Dossards'],
            ['category' => 'Apparel']
        );

        return EquipmentItem::create(array_merge([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
        ], $attributes));
    }

    private function issue(EquipmentItem $item, int $quantity): EquipmentRental
    {
        return EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
            'quantity' => $quantity,
        ]);
    }

    private function summary(): array
    {
        $response = $this->actingAs($this->user())->get(route('equipment.inventory'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        return $props['summary'];
    }

    #[Test]
    public function the_total_counts_units_not_rows(): void
    {
        $this->lot(50);
        $this->lot(20);

        $this->assertSame(70, $this->summary()['total']);
    }

    #[Test]
    public function issued_units_are_reported_as_rented_even_though_the_lot_stays_available(): void
    {
        $lot = $this->lot(20);
        $this->issue($lot, 8);

        $this->assertSame('Available', $lot->fresh()->status, 'a multi-unit lot keeps its status');

        $summary = $this->summary();
        $this->assertSame(20, $summary['total']);
        $this->assertSame(8, $summary['rented']);
        $this->assertSame(12, $summary['available']);
    }

    #[Test]
    public function a_blocked_lot_contributes_no_available_units(): void
    {
        $this->lot(20);
        $this->lot(6, ['status' => 'Under Repair']);

        $summary = $this->summary();
        $this->assertSame(26, $summary['total']);
        $this->assertSame(20, $summary['available']);
        $this->assertSame(6, $summary['under_repair']);
    }

    #[Test]
    public function partial_returns_come_back_into_the_available_pool(): void
    {
        $lot = $this->lot(20);
        $rental = $this->issue($lot, 10);
        $rental->update(['returned_quantity' => 6]);

        $summary = $this->summary();
        $this->assertSame(4, $summary['rented']);
        $this->assertSame(16, $summary['available']);
    }
}
