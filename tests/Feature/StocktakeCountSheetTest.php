<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\User;
use App\Services\Equipment\EquipmentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The count sheet lists only units in the club, and counters type numbers. */
class StocktakeCountSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function lot(int $quantity, string $status = 'Available'): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(['name' => 'Cones'], ['category' => 'Training']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01',
            'quantity' => $quantity, 'status' => $status, 'condition' => 'Good',
        ]);
    }

    private function lend(EquipmentItem $item, int $quantity): void
    {
        app(EquipmentLifecycleService::class)->rentOut($item, null, [
            'type' => 'rental', 'quantity' => $quantity, 'checkout_date' => now()->toDateTimeString(),
            'external_name' => 'نادي الجيران', 'external_phone' => '0555000000',
        ]);
    }

    private function start(): InventorySession
    {
        $this->actingAs($this->admin)->post(route('inventory.store'), ['type' => 'monthly', 'session_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        return InventorySession::latest('id')->firstOrFail();
    }

    private function line(InventorySession $s, EquipmentItem $item): ?InventorySessionItem
    {
        return $s->items()->where('equipment_item_id', $item->id)->first();
    }

    #[Test]
    public function units_on_loan_are_left_off_the_expected_count_and_noted(): void
    {
        $lot = $this->lot(20);
        $this->lend($lot, 5);

        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->assertSame(15, $line->expected_quantity);
        $this->assertSame(5, $line->on_loan_quantity);
        $this->assertSame(15, $session->fresh()->total_expected);
    }

    #[Test]
    public function an_item_fully_on_loan_has_no_line(): void
    {
        $unit = $this->lot(1);
        $this->lend($unit, 1);

        $this->assertNull($this->line($this->start(), $unit));
    }

    #[Test]
    public function lost_lots_are_off_the_sheet_and_under_repair_stays(): void
    {
        $lost = $this->lot(3, 'Lost');
        $repair = $this->lot(1, 'Under Repair');
        $retired = $this->lot(2, 'Retired');

        $session = $this->start();

        $this->assertNull($this->line($session, $lost));
        $this->assertNull($this->line($session, $retired));
        $this->assertSame(1, $this->line($session, $repair)->expected_quantity);
    }

    #[Test]
    public function a_typed_partial_count_writes_off_only_the_missing_units(): void
    {
        $lot = $this->lot(40);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 38, 'note' => 'قمعان تحت المدرج؟'],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('inventory.complete', $session))->assertSessionHasNoErrors();

        $this->assertSame(38, $lot->fresh()->quantity);
        $this->assertSame(2, (int) EquipmentItem::where('status', 'Lost')->sum('quantity'));
        $this->assertSame('قمعان تحت المدرج؟', $line->fresh()->note);
        $this->assertSame(38, $session->fresh()->total_found);
        $this->assertSame(2, $session->fresh()->total_missing);
    }

    #[Test]
    public function a_count_above_expected_is_refused(): void
    {
        $lot = $this->lot(20);
        $this->lend($lot, 5);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 16],
        ]])->assertSessionHasErrors('items.0.found_quantity');

        $this->assertFalse($line->fresh()->counted);
    }

    #[Test]
    public function a_loan_made_during_the_count_is_never_written_off(): void
    {
        $lot = $this->lot(10);
        $session = $this->start();
        $line = $this->line($session, $lot);
        $this->lend($lot, 4); // after the sheet was made

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 5],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('inventory.complete', $session))->assertSessionHasNoErrors();

        // 5 expected-but-missing, but only 6 are in the club and 5 were seen: write off 1.
        $this->assertSame(1, (int) EquipmentItem::where('status', 'Lost')->sum('quantity'));
        $this->assertSame(9, $lot->fresh()->quantity);
    }

    /** @return array<string, array{0: string}> */
    public static function leftStock(): array
    {
        return ['lost' => ['Lost'], 'retired' => ['Retired']];
    }

    #[Test]
    #[DataProvider('leftStock')]
    public function an_item_lost_or_retired_since_the_sheet_was_made_is_skipped(string $status): void
    {
        $lot = $this->lot(6);
        $session = $this->start();
        $line = $this->line($session, $lot);
        $lot->update(['status' => $status]);

        // Partial count: without the skip, 4 units would be split into a new Lost lot.
        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 2],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('inventory.complete', $session))->assertSessionHasNoErrors();

        $this->assertSame(1, EquipmentItem::count(), 'no second lost lot');
        $this->assertSame(6, $lot->fresh()->quantity);
        $this->assertSame(0, EquipmentHistory::where('event_type', 'Lost')->count());
        // Totals still reflect the sheet.
        $this->assertSame(2, $session->fresh()->total_found);
        $this->assertSame(4, $session->fresh()->total_missing);
    }

    #[Test]
    public function missing_lot_with_a_loan_writes_off_only_the_units_in_the_club(): void
    {
        $lot = $this->lot(20);
        $this->lend($lot, 5);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => false],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('inventory.complete', $session))->assertSessionHasNoErrors();

        $lost = EquipmentItem::where('status', 'Lost')->get();
        $this->assertCount(1, $lost);
        $this->assertSame(15, $lost->first()->quantity);
        $this->assertSame(5, $lot->fresh()->quantity);
        $this->assertNotSame('Lost', $lot->fresh()->status);
        $this->assertSame(1, $lot->rentals()->whereNull('return_date')->count());
    }

    #[Test]
    public function found_with_zero_quantity_is_stored_as_not_found(): void
    {
        $lot = $this->lot(10);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true, 'found_quantity' => 0],
        ]])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $line->fresh()->found);
        $this->assertSame(0, $line->fresh()->found_quantity);
    }

    #[Test]
    public function found_without_a_quantity_means_the_whole_line(): void
    {
        $lot = $this->lot(40);
        $session = $this->start();
        $line = $this->line($session, $lot);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => true],
        ]])->assertSessionHasNoErrors();

        $this->assertTrue((bool) $line->fresh()->found);
        $this->assertSame(40, $line->fresh()->found_quantity);
    }

    #[Test]
    public function a_single_unit_marked_missing_is_written_off(): void
    {
        $unit = $this->lot(1, 'Under Repair');
        $session = $this->start();
        $line = $this->line($session, $unit);

        $this->actingAs($this->admin)->put(route('inventory.counts', $session), ['items' => [
            ['id' => $line->id, 'found' => false],
        ]]);
        $this->actingAs($this->admin)->post(route('inventory.complete', $session));

        $this->assertSame('Lost', $unit->fresh()->status);
    }

    #[Test]
    public function the_list_tile_counts_what_a_new_count_would_expect(): void
    {
        $lot = $this->lot(20);
        $this->lend($lot, 5);
        $this->lot(3, 'Lost');

        $this->actingAs($this->admin)->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('itemCount', 15));

        $this->assertSame(15, (int) $this->start()->total_expected);
    }
}
