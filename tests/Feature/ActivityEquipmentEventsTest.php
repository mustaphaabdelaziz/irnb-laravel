<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityEquipmentEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202690000;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Dossards '.++$this->membership, 'category' => 'Apparel']);
    }

    /** Same fixture as EquipmentLotRentalTest. */
    private function lot(int $quantity = 20): EquipmentItem
    {
        return EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
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

    /** Same shape as EquipmentImportExportTest::makeCsv. */
    private function importCsv(array $dataRows): UploadedFile
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 6, 'header'));
        foreach ($dataRows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        $path = tempnam(sys_get_temp_dir(), 'acteq').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'items.csv', 'text/csv', null, true);
    }

    /** @return Collection<int, ActivityLog> */
    private function events(string $action): Collection
    {
        return ActivityLog::query()->where('action', $action)->orderBy('id')->get();
    }

    private function assertEvent(ActivityLog $event, User $actor, ?object $subject, array $properties): void
    {
        $this->assertSame($actor->id, $event->user_id);
        if ($subject) {
            $this->assertSame($subject->getMorphClass(), $event->subject_type);
            $this->assertSame($subject->getKey(), (int) $event->subject_id);
        } else {
            $this->assertNull($event->subject_type);
            $this->assertNull($event->subject_id);
        }
        $this->assertEqualsCanonicalizing(array_keys($properties), array_keys($event->properties ?? []));
        foreach ($properties as $key => $value) {
            $this->assertEquals($value, $event->properties[$key], $key);
        }
    }

    // ── Receiving stock ──────────────────────────────────────────────

    #[Test]
    public function receiving_stock_records_stock_received_with_the_quantity(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();

        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id, 'quantity' => 12,
            'purchase_date' => '2026-03-15', 'condition' => 'New',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lot = EquipmentItem::query()->sole();
        $events = $this->events(ActivityAction::STOCK_RECEIVED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $lot, ['quantity' => 12]);
        $this->assertCount(0, $this->events(ActivityAction::TRANSACTION_RECORDED));
    }

    #[Test]
    public function a_paid_receipt_records_both_the_stock_and_the_expense_once(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();

        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id, 'quantity' => 10, 'unit_price' => 120,
            'purchase_date' => '2026-03-15', 'condition' => 'New', 'record_expense' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lot = EquipmentItem::query()->sole();
        $tx = Transaction::query()->sole();

        $stock = $this->events(ActivityAction::STOCK_RECEIVED);
        $this->assertCount(1, $stock);
        $this->assertEvent($stock[0], $admin, $lot, ['quantity' => 10]);

        $money = $this->events(ActivityAction::TRANSACTION_RECORDED);
        $this->assertCount(1, $money);
        $this->assertEvent($money[0], $admin, $tx, ['amount' => 1200, 'type' => 'expense']);
        $this->assertSame(2, ActivityLog::count());
    }

    #[Test]
    public function adding_a_single_serialized_item_records_stock_received_of_one(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();

        $this->actingAs($admin)->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-04-01', 'condition' => 'Good',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item = EquipmentItem::query()->sole();
        $events = $this->events(ActivityAction::STOCK_RECEIVED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $item, ['quantity' => 1]);
        $this->assertSame(1, ActivityLog::count());
    }

    // ── Import ───────────────────────────────────────────────────────

    #[Test]
    public function an_import_records_one_summary_event_and_no_per_row_events(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();

        $this->actingAs($admin)->post(route('equipment.items.import', $catalog), ['file' => $this->importCsv([
            ['Shirt 10', '2026-05-01', 'New', 'Locker A', '1500', ''],
            ['Shirt 11', '2026-05-01', 'Good', 'Locker A', '', ''],
            ['Shirt 12', '2026-05-01', 'Good', '', '', ''],
        ])])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(3, EquipmentItem::where('catalog_id', $catalog->id)->count());

        $events = $this->events(ActivityAction::EQUIPMENT_IMPORTED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $catalog, ['count' => 3]);
        $this->assertCount(0, $this->events(ActivityAction::STOCK_RECEIVED));
        $this->assertCount(0, $this->events(ActivityAction::TRANSACTION_RECORDED));
        $this->assertSame(1, ActivityLog::count());
    }

    #[Test]
    public function an_import_that_imports_nothing_records_nothing(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();

        $this->actingAs($admin)->post(route('equipment.items.import', $catalog), ['file' => $this->importCsv([
            ['', '', '', '', '', ''],
        ])])->assertRedirect();

        $this->assertSame(0, EquipmentItem::count());
        $this->assertSame(0, ActivityLog::count());
    }

    // ── Rentals and assignments ──────────────────────────────────────

    #[Test]
    public function renting_records_equipment_rented_with_the_quantity_only(): void
    {
        $admin = $this->admin();
        $lot = $this->lot(20);

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'External',
            'external_name' => 'Karim Visitor',
            'external_phone' => '0555000000',
            'type' => 'rental',
            'quantity' => 4,
        ])->assertSessionHasNoErrors();

        $rental = EquipmentRental::query()->sole();
        $events = $this->events(ActivityAction::EQUIPMENT_RENTED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $rental, ['quantity' => 4]);
        $this->assertCount(0, $this->events(ActivityAction::EQUIPMENT_ASSIGNED));
        $this->assertStringNotContainsString('Karim', json_encode($events[0]->properties));
    }

    #[Test]
    public function assigning_records_equipment_assigned(): void
    {
        $admin = $this->admin();
        $lot = $this->lot(20);

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'Player',
            'rentable_id' => $this->player()->id,
            'type' => 'assignment',
            'quantity' => 2,
        ])->assertSessionHasNoErrors();

        $rental = EquipmentRental::query()->sole();
        $events = $this->events(ActivityAction::EQUIPMENT_ASSIGNED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $rental, ['quantity' => 2]);
        $this->assertCount(0, $this->events(ActivityAction::EQUIPMENT_RENTED));
    }

    #[Test]
    public function a_refused_rental_records_nothing(): void
    {
        $admin = $this->admin();
        $lot = $this->lot(2);

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'Player',
            'rentable_id' => $this->player()->id,
            'quantity' => 5,
        ])->assertSessionHas('error');

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function partial_and_final_returns_each_record_the_quantity_returned(): void
    {
        $admin = $this->admin();
        $lot = $this->lot(20);

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'Player',
            'rentable_id' => $this->player()->id,
            'quantity' => 10,
        ])->assertSessionHasNoErrors();
        $rental = EquipmentRental::query()->sole();

        $this->actingAs($admin)->post(route('equipment.rentals.return', $rental), ['quantity' => 6])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->actingAs($admin)->post(route('equipment.rentals.return', $rental), [])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $events = $this->events(ActivityAction::EQUIPMENT_RETURNED);
        $this->assertCount(2, $events);
        $this->assertEvent($events[0], $admin, $rental, ['quantity' => 6]);
        $this->assertEvent($events[1], $admin, $rental, ['quantity' => 4]);
    }

    #[Test]
    public function a_refused_return_records_nothing(): void
    {
        $admin = $this->admin();
        $rental = EquipmentRental::create([
            'equipment_item_id' => $this->lot(20)->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
            'quantity' => 3,
        ]);

        $this->actingAs($admin)->post(route('equipment.rentals.return', $rental), ['quantity' => 9])
            ->assertSessionHas('error');

        $this->assertSame(0, ActivityLog::count());
    }

    // ── Stocktake ────────────────────────────────────────────────────

    #[Test]
    public function a_stocktake_started_by_one_user_and_completed_by_another_credits_each(): void
    {
        $starter = $this->admin();
        $finisher = $this->admin();
        $lot = $this->lot(50);

        $this->actingAs($starter)->post(route('inventory.store'), [
            'type' => 'ad_hoc', 'session_date' => '2026-07-20',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $session = InventorySession::query()->sole();
        $started = $this->events(ActivityAction::STOCKTAKE_STARTED);
        $this->assertCount(1, $started);
        $this->assertEvent($started[0], $starter, $session, []);

        $line = InventorySessionItem::where('inventory_session_id', $session->id)
            ->where('equipment_item_id', $lot->id)->sole();
        $line->update(['counted' => true, 'found' => true, 'found_quantity' => 47]);

        $this->actingAs($finisher)->post(route('inventory.complete', $session))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $completed = $this->events(ActivityAction::STOCKTAKE_COMPLETED);
        $this->assertCount(1, $completed);
        $this->assertEvent($completed[0], $finisher, $session, ['found' => 47, 'missing' => 3]);
        $this->assertSame(2, ActivityLog::count());
    }
}
