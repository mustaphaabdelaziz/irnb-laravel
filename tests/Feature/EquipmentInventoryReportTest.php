<?php

namespace Tests\Feature;

use App\Models\Branch;
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

    private function props(): array
    {
        $response = $this->actingAs($this->user())->get(route('equipment.inventory'));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    #[Test]
    public function asset_value_multiplies_units_by_the_batch_price(): void
    {
        $this->lot(50, ['unit_price' => 120]);
        $this->lot(10, ['unit_price' => 135]);

        $this->assertEqualsWithDelta(7350, $this->props()['totalValue'], 0.01);
    }

    #[Test]
    public function lost_stock_is_excluded_from_asset_value(): void
    {
        $this->lot(50, ['unit_price' => 120]);
        $this->lot(10, ['unit_price' => 120, 'status' => 'Lost']);

        $this->assertEqualsWithDelta(6000, $this->props()['totalValue'], 0.01);
    }

    #[Test]
    public function club_wide_stock_is_reported_separately_from_branch_value(): void
    {
        $branch = Branch::create(['name' => 'Football', 'name_en' => 'Football']);

        $tagged = $this->lot(20, ['unit_price' => 100]);
        $tagged->branches()->sync([$branch->id]);

        $this->lot(5, ['unit_price' => 100]);   // untagged: club-wide

        $props = $this->props();

        $this->assertEqualsWithDelta(2000, $props['valueByBranch'][0]['value'], 0.01);
        // Untagged stock must not vanish through the branch join.
        $this->assertSame(5, $props['clubWideValue']['units']);
        $this->assertEqualsWithDelta(2500, $props['totalValue'], 0.01);
    }

    #[Test]
    public function an_assignment_never_appears_as_overdue(): void
    {
        $lot = $this->lot(5);

        EquipmentRental::create([
            'equipment_item_id' => $lot->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now()->subYear(),
            'due_date' => now()->subMonths(6),
            'type' => 'assignment',
        ]);

        $this->assertCount(0, $this->props()['overdueRentals']);
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
