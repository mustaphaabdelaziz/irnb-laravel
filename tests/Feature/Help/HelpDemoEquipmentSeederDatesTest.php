<?php

namespace Tests\Feature\Help;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentHistory;
use App\Models\EquipmentRental;
use App\Models\InventorySession;
use App\Models\StorageLocation;
use Database\Seeders\HelpDemoEquipmentSeeder;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The demo store room holds together whatever day the screenshots are taken. */
class HelpDemoEquipmentSeederDatesTest extends TestCase
{
    use RefreshDatabase;

    public static function seedDays(): array
    {
        return [
            // January: the completed count (D−35) falls in the previous calendar year.
            'mid-season, new year' => ['2027-01-20 10:00:00', 'INV-2026-01', 'INV-2027-01'],
            // Ten days into the season: the D−35 count falls in the previous season.
            'season start' => ['2026-09-10 10:00:00', 'INV-2026-01', 'INV-2026-02'],
        ];
    }

    #[Test]
    #[DataProvider('seedDays')]
    public function the_store_room_is_consistent_on_any_seed_day(string $day, string $doneRef, string $openRef): void
    {
        $this->travelTo($day);
        $this->seed(HelpDemoSeeder::class);

        $catalogs = EquipmentCatalog::orderBy('id')->get();
        $this->assertSame([20, 40, 30, 3, 2, 25], $catalogs->map->total_quantity->all());
        $this->assertSame([18, 40, 30, 2, 1, 24], $catalogs->map->available_count->all());

        $done = InventorySession::where('status', 'completed')->sole();
        $open = InventorySession::where('status', 'in_progress')->sole();
        $this->assertSame([$doneRef, $openRef], [$done->reference, $open->reference]);
        $this->assertGreaterThan(0, $done->items()->count());
        $this->assertGreaterThan(0, $open->items()->count());
        $this->assertSame((int) $done->total_expected, (int) $done->total_found);
        $this->assertSame(now()->subDays(HelpDemoEquipmentSeeder::COMPLETED_STOCKTAKE_DAYS_AGO)->toDateString(), $done->session_date->toDateString());

        $this->assertSame(0, EquipmentHistory::where('event_timestamp', '>', now())->count());
        $this->assertSame(0, EquipmentRental::where('checkout_date', '>', now())->count());
        $this->assertSame(0, EquipmentRental::where('return_date', '>', now())->count());

        // Nothing is lent before it arrived.
        foreach (EquipmentRental::with('equipmentItem')->get() as $rental) {
            $this->assertGreaterThanOrEqual(
                $rental->equipmentItem->purchase_date->toDateString(),
                $rental->checkout_date->toDateString(),
            );
        }
    }

    #[Test]
    public function it_refuses_a_real_database_on_its_own(): void
    {
        $this->app['env'] = 'production';

        try {
            app(HelpDemoEquipmentSeeder::class)->run();
            $this->fail('The equipment seeder ran in production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('throwaway database', $e->getMessage());
        }

        $this->assertSame(0, EquipmentCatalog::count());
        $this->assertSame(0, StorageLocation::count());
        $this->assertTrue(EquipmentCategory::where('name', 'Balls')->exists());
    }
}
