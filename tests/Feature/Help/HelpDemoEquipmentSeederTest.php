<?php

namespace Tests\Feature\Help;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\InventorySession;
use App\Models\Player;
use App\Models\StorageLocation;
use App\Models\Transaction;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Database\Seeders\HelpDemoEquipmentSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoEquipmentSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);
    }

    private function catalog(string $name): EquipmentCatalog
    {
        return EquipmentCatalog::where('name', $name)->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, EquipmentRental> */
    private function rentals(EquipmentCatalog $catalog)
    {
        return EquipmentRental::with('rentable')
            ->whereIn('equipment_item_id', $catalog->items()->pluck('id'))->get();
    }

    #[Test]
    public function catalogs_categories_and_storage_places_exist(): void
    {
        $this->assertSame(
            [Demo::BALL, Demo::CONE, Demo::BIB, Demo::STOPWATCH, Demo::GOAL, Demo::GOGGLES],
            EquipmentCatalog::orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame(range(1, 6), EquipmentCatalog::orderBy('id')->pluck('id')->all());

        $codes = EquipmentCategory::orderBy('id')->pluck('code', 'name')->all();
        $this->assertSame([
            'كرات' => 'BALL', 'مرامي وشباك' => 'GOAL', 'معدات التدريب' => 'TRN', 'ملابس' => 'APP',
            'معدات الحماية' => 'PRT', 'إكسسوارات' => 'ACC', 'صيانة' => 'MNT', 'أخرى' => 'OTH',
        ], $codes);
        $this->assertSame('كرات', $this->catalog(Demo::BALL)->category);
        $this->assertSame('إكسسوارات', $this->catalog(Demo::STOPWATCH)->category);

        $this->assertEqualsCanonicalizing(
            [Demo::MAIN_STORE, Demo::CHANGING_ROOM, Demo::POOL_STORE],
            StorageLocation::pluck('name')->all(),
        );
    }

    #[Test]
    public function the_balls_are_two_short_and_overdue_with_ahmed(): void
    {
        $catalog = $this->catalog(Demo::BALL);
        $this->assertSame(20, $catalog->total_quantity);
        $this->assertSame(18, $catalog->available_count);

        $rentals = $this->rentals($catalog);
        $this->assertCount(1, $rentals);
        $rental = $rentals->first();
        $this->assertNull($rental->return_date);
        $this->assertSame('rental', $rental->type);
        $this->assertSame(2, $rental->quantity);
        $this->assertInstanceOf(Player::class, $rental->rentable);
        $this->assertSame(['أحمد', 'بن علي'], [$rental->rentable->firstname, $rental->rentable->lastname]);
        $this->assertTrue($rental->is_overdue);
        $this->assertSame('2026-11-08', $rental->checkout_date->toDateString());
        $this->assertSame('2026-11-15', $rental->due_date->toDateString());
    }

    #[Test]
    public function the_cones_came_back(): void
    {
        $catalog = $this->catalog(Demo::CONE);
        $this->assertSame(40, $catalog->available_count);

        $rental = $this->rentals($catalog)->sole();
        $this->assertNotNull($rental->return_date);
        $this->assertSame(10, $rental->returned_quantity);
        $this->assertSame('قاسمي', $rental->rentable->lastname);
        $this->assertSame('2026-10-29', $rental->checkout_date->toDateString());
        $this->assertSame('2026-11-05', $rental->return_date->toDateString());
    }

    #[Test]
    public function three_bibs_are_damaged(): void
    {
        $lots = $this->catalog(Demo::BIB)->items()->orderBy('id')->get(['quantity', 'condition', 'location', 'received_via']);
        $this->assertSame([[27, 'Good'], [3, 'Damaged']], $lots->map(fn ($l) => [$l->quantity, $l->condition])->all());
        $this->assertSame([Demo::CHANGING_ROOM], $lots->pluck('location')->unique()->values()->all());
        $this->assertSame(['donation'], $lots->pluck('received_via')->unique()->values()->all());
    }

    #[Test]
    public function stopwatches_are_numbered_and_one_is_in_repair(): void
    {
        $units = $this->catalog(Demo::STOPWATCH)->items()->orderBy('id')->get();
        $this->assertCount(3, $units);
        $this->assertSame(['ساعة توقيت 1', 'ساعة توقيت 2', 'ساعة توقيت 3'], $units->pluck('designation')->all());
        $this->assertSame(
            ['CLUB-2026-ACC-004-00001', 'CLUB-2026-ACC-004-00002', 'CLUB-2026-ACC-004-00003'],
            $units->pluck('unique_identifier')->all(),
        );
        $this->assertSame(['Available', 'Under Repair', 'Available'], $units->pluck('status')->all());
        $this->assertSame(['opening_balance'], $units->pluck('received_via')->unique()->values()->all());
    }

    #[Test]
    public function goal_one_is_out_with_an_outside_person(): void
    {
        $units = $this->catalog(Demo::GOAL)->items()->orderBy('id')->get();
        $this->assertSame(['مرمى 1', 'مرمى 2'], $units->pluck('designation')->all());
        $this->assertSame(['Rented', 'Available'], $units->pluck('status')->all());
        $this->assertSame(['CLUB-2026-GOAL-005-00001', 'CLUB-2026-GOAL-005-00002'], $units->pluck('unique_identifier')->all());

        $rental = EquipmentRental::where('equipment_item_id', $units[0]->id)->sole();
        $this->assertNull($rental->rentable_id);
        $this->assertSame(Demo::OUTSIDER, $rental->external_name);
        $this->assertSame(Demo::OUTSIDER_PHONE, $rental->external_phone);
        $this->assertFalse($rental->is_overdue);
        $this->assertSame('2026-11-21', $rental->due_date->toDateString());
        $this->assertSame(0, EquipmentRental::where('equipment_item_id', $units[1]->id)->count());
    }

    #[Test]
    public function one_pair_of_goggles_is_handed_out_to_a_swimmer(): void
    {
        $rental = $this->rentals($this->catalog(Demo::GOGGLES))->sole();
        $this->assertSame('assignment', $rental->type);
        $this->assertNull($rental->return_date);
        $this->assertSame(1, $rental->quantity);
        $this->assertTrue($rental->rentable->registrations()
            ->whereHas('branch', fn ($q) => $q->where('name', 'Swimming'))->exists());
    }

    #[Test]
    public function history_falls_on_each_step_s_day_and_the_clock_is_restored(): void
    {
        $this->assertSame('2026-11-18 10:00:00', now()->toDateTimeString());
        $this->assertSame(0, EquipmentHistory::where('event_timestamp', '>', now())->count());

        $events = fn (string $catalog, string $type) => EquipmentHistory::where('event_type', $type)
            ->whereIn('item_id', $this->catalog($catalog)->items()->pluck('id'))
            ->get()->map(fn ($h) => substr((string) $h->event_timestamp, 0, 10))->unique()->values()->all();

        $this->assertSame(['2026-08-20'], $events(Demo::GOAL, 'Received'));
        $this->assertSame(['2026-11-16'], $events(Demo::GOAL, 'Checkout'));
        $this->assertSame(['2026-11-08'], $events(Demo::BALL, 'Checkout'));
        $this->assertSame(['2026-11-05'], $events(Demo::CONE, 'Return'));
        $this->assertSame(['2026-11-13'], $events(Demo::STOPWATCH, 'Repair'));
        $this->assertSame(['2026-11-03'], $events(Demo::BIB, 'Split Out'));

        $demo = \App\Models\User::where('username', HelpDemoSeeder::USERNAME)->value('id');
        $this->assertSame(0, EquipmentHistory::where('user_id', '!=', $demo)->orWhereNull('user_id')->count());
    }

    #[Test]
    public function no_money_moves_and_the_finance_totals_stay_the_same(): void
    {
        // The finance seeder's two «Equipment» entries (last season's 45 000, this season's 18 000).
        $this->assertSame(2, Transaction::where('category', 'equipment')->count());
        $this->assertSame(0, EquipmentItem::whereNotNull('purchase_transaction_id')->count());

        $totals = app(FinanceService::class)->yearTotals(FiscalPeriod::currentKey());
        $this->assertEqualsWithDelta(100700, (float) $totals['expense'], 0.01);
    }

    #[Test]
    public function one_stock_take_is_done_and_one_is_in_progress(): void
    {
        $this->assertSame(2, InventorySession::count());

        $done = InventorySession::where('status', 'completed')->sole();
        $this->assertSame('yearly', $done->type);
        $this->assertSame('INV-2026-01', $done->reference);
        $this->assertSame(0, (int) $done->total_missing);
        $this->assertGreaterThan(0, (int) $done->total_expected);
        $this->assertSame((int) $done->total_expected, (int) $done->total_found);
        $this->assertSame(0, $done->items()->where('found', false)->count());

        $open = InventorySession::where('status', 'in_progress')->sole();
        $this->assertSame('monthly', $open->type);
        $this->assertSame('INV-2026-02', $open->reference);
        $this->assertSame('2026-11-18', $open->session_date->toDateString());
        $this->assertSame(Demo::STOCKTAKE_NOTES, $open->notes);
        $this->assertSame(0, $open->items()->where('counted', true)->count());
        $this->assertSame((int) EquipmentItem::where('status', '!=', 'Retired')->sum('quantity'), (int) $open->total_expected);
        $this->assertSame(EquipmentItem::count(), $open->items()->count());
        $participant = $open->participants()->with('participant')->sole()->participant;
        $this->assertInstanceOf(Player::class, $participant);
        $this->assertSame('قاسمي', $participant->lastname);

        $this->assertSame(0, EquipmentItem::where('status', 'Lost')->count());
    }
}
