<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\User;
use App\Services\Equipment\EquipmentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Loan and repair bugs found while writing the equipment help articles. */
class EquipmentLoanRepairFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600000;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function lot(int $quantity = 20, string $status = 'Available'): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(['name' => 'Dossards'], ['category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
            'status' => $status,
        ]);
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

    private function lend(EquipmentItem $item, int $quantity): EquipmentRental
    {
        return app(EquipmentLifecycleService::class)->rentOut($item, $this->player(), [
            'quantity' => $quantity,
            'checkout_date' => '2026-03-01',
        ]);
    }

    #[Test]
    public function a_partial_return_records_the_typed_return_date_in_the_history(): void
    {
        $item = $this->lot();
        $rental = $this->lend($item, 10);
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('equipment.rentals.return', $rental), ['quantity' => 4, 'return_date' => '2026-03-05'])
            ->assertSessionHas('success');

        $this->assertNull($rental->fresh()->return_date);
        $event = EquipmentHistory::where('item_id', $item->id)->where('event_type', 'Return')->sole();
        $this->assertSame('2026-03-05', $event->details['return_date']);

        // The full return still closes the rental on its typed date, and logs it too.
        $this->actingAs($user)
            ->post(route('equipment.rentals.return', $rental), ['return_date' => '2026-03-09'])
            ->assertSessionHas('success');

        $this->assertSame('2026-03-09', $rental->fresh()->return_date->toDateString());
        $last = EquipmentHistory::where('item_id', $item->id)->where('event_type', 'Return')->latest('id')->first();
        $this->assertSame('2026-03-09', $last->details['return_date']);

        $this->actingAs($user)
            ->get(route('equipment.items.history', $item))
            ->assertInertia(fn (Assert $page) => $page
                ->where('history', fn ($history) => collect($history)->pluck('details.return_date')->filter()->sort()->values()->all()
                    === ['2026-03-05', '2026-03-09']));
    }

    #[Test]
    public function marking_a_lot_lost_closes_every_open_loan_of_it(): void
    {
        $item = $this->lot();
        $first = $this->lend($item, 3);
        $second = $this->lend($item, 5);

        $this->actingAs($this->user())
            ->post(route('equipment.items.mark-lost', $item))
            ->assertSessionHas('success');

        foreach ([$first, $second] as $rental) {
            $rental->refresh();
            $this->assertNotNull($rental->return_date);
            $this->assertSame($rental->quantity, $rental->returned_quantity);
            $this->assertNull($rental->return_notes, 'no English note');
        }
        $this->assertSame(0, $item->openRentals()->count());
        $this->assertSame('Lost', $item->fresh()->status);
    }

    #[Test]
    public function the_catalog_strip_counts_a_lot_under_repair_apart_from_rented_units(): void
    {
        $lent = $this->lot(10);
        $this->lend($lent, 4);
        $repair = $this->lot(6, 'Under Repair');

        $this->actingAs($this->user())
            ->get(route('equipment.catalogs.show', $lent->catalog_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('availableCount', 6)
                ->where('totalQuantity', 16)
                ->where('rentedCount', 4)
                ->where('underRepairCount', 6));

        $this->assertSame('Under Repair', $repair->fresh()->status);
    }

    #[Test]
    public function a_lot_with_units_out_cannot_be_sent_to_repair(): void
    {
        $item = $this->lot();
        $this->lend($item, 2);

        $this->actingAs($this->user())
            ->post(route('equipment.items.repair', $item))
            ->assertRedirect()
            ->assertSessionHas('error', ['key' => 'flash.equipment_repair_units_out', 'params' => []]);

        $this->assertSame('Available', $item->fresh()->status);
        $this->assertSame(0, EquipmentHistory::where('item_id', $item->id)->where('event_type', 'Repair')->count());
    }

    #[Test]
    public function a_single_unit_out_on_loan_cannot_be_sent_to_repair_either(): void
    {
        $item = $this->lot(1);
        $this->lend($item, 1);

        $this->actingAs($this->user())
            ->post(route('equipment.items.repair', $item))
            ->assertSessionHas('error', ['key' => 'flash.equipment_repair_units_out', 'params' => []]);

        $this->assertSame('Rented', $item->fresh()->status);
    }

    #[Test]
    public function a_lot_with_every_unit_in_hand_goes_to_repair(): void
    {
        $item = $this->lot();

        $this->actingAs($this->user())
            ->post(route('equipment.items.repair', $item))
            ->assertSessionHas('success', 'flash.equipment_sent_to_repair');

        $this->assertSame('Under Repair', $item->fresh()->status);
    }
}
