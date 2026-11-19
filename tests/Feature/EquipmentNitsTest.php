<?php

namespace Tests\Feature;

use App\Exceptions\Equipment\StockException;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Equipment\EquipmentLifecycleService;
use App\Services\Equipment\EquipmentStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Small equipment flaws left over from writing the help articles. */
class EquipmentNitsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function lot(int $quantity = 20, string $condition = 'Good'): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(['name' => 'Dossards'], ['category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
            'condition' => $condition,
            'status' => 'Available',
        ]);
    }

    private function player(): Player
    {
        return Player::create(['firstname' => 'Ali', 'lastname' => 'B', 'membership_id' => '202600901', 'join_year' => 2026]);
    }

    #[Test]
    public function a_damaged_lot_is_not_available_and_cannot_be_lent(): void
    {
        $lot = $this->lot(20);
        $damaged = app(EquipmentStockService::class)->splitLot($lot, 3, 'Damaged');

        $this->assertSame(0, $damaged->fresh()->available_quantity);
        $this->assertSame(17, $lot->fresh()->available_quantity);

        $this->expectException(StockException::class);
        app(EquipmentLifecycleService::class)->rentOut($damaged->fresh(), $this->player(), ['quantity' => 1]);
    }

    #[Test]
    public function a_damaged_lot_comes_back_through_repair(): void
    {
        $damaged = $this->lot(3, 'Damaged');
        $service = app(EquipmentLifecycleService::class);

        $service->sendToRepair($damaged);
        $service->completeRepair($damaged->fresh());

        $this->assertSame(3, $damaged->fresh()->available_quantity);
    }

    #[Test]
    public function the_inventory_page_counts_damaged_units_apart_from_available_ones(): void
    {
        $this->lot(20);
        $this->lot(3, 'Damaged');

        $this->actingAs($this->user())->get(route('equipment.inventory'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 23)
                ->where('summary.available', 20)
                ->where('summary.damaged', 3)
                ->where('categoryBreakdown.0.available', 20));
    }

    #[Test]
    public function a_loan_is_late_only_after_its_due_day(): void
    {
        $lot = $this->lot();
        $rental = app(EquipmentLifecycleService::class)->rentOut($lot, $this->player(), [
            'quantity' => 1, 'checkout_date' => today()->subDays(5), 'due_date' => today(),
        ]);

        $this->assertFalse($rental->fresh()->is_overdue, 'the due day itself is on time');

        EquipmentRental::whereKey($rental->id)->update(['due_date' => today()->subDay()]);
        $this->assertTrue($rental->fresh()->is_overdue);
    }

    #[Test]
    public function an_arabic_only_category_needs_a_typed_code(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('equipment-categories.store'), ['name' => 'كرات'])
            ->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('equipment_categories', ['name' => 'كرات']);

        $this->actingAs($user)->post(route('equipment-categories.store'), ['name' => 'كرات', 'code' => 'ball'])
            ->assertSessionHasNoErrors();
        $this->assertSame('BALL', EquipmentCategory::where('name', 'كرات')->value('code'));

        // A Latin name still gets its code by itself.
        $this->actingAs($user)->post(route('equipment-categories.store'), ['name' => 'Cones'])
            ->assertSessionHasNoErrors();
        $this->assertSame('CONE', EquipmentCategory::where('name', 'Cones')->value('code'));
    }

    #[Test]
    public function every_report_card_has_its_own_id(): void
    {
        $ids = collect(app(ReportRegistry::class)->cards($this->user()))->pluck('id');

        $this->assertSame($ids->count(), $ids->unique()->count(), 'duplicate: '.$ids->duplicates()->implode(', '));
    }
}
