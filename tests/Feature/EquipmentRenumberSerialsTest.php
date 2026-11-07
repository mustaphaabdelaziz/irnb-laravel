<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\WebsiteConfig;
use App\Services\Equipment\SerialNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentRenumberSerialsTest extends TestCase
{
    use RefreshDatabase;

    private EquipmentCatalog $shirts;

    private EquipmentCatalog $shorts;

    protected function setUp(): void
    {
        parent::setUp();

        $config = WebsiteConfig::singleton();
        $config->club_short_name = 'IRNB';
        $config->save();
        EquipmentCategory::updateOrCreate(['name' => 'Balls'], ['code' => 'BALL']);

        $this->shirts = EquipmentCatalog::create(['name' => 'Shirts', 'category' => 'Balls', 'requires_serial' => true]);
        $this->shorts = EquipmentCatalog::create(['name' => 'Shorts', 'category' => 'Balls', 'requires_serial' => true]);
    }

    private function unit(EquipmentCatalog $catalog, string $serial, string $date): EquipmentItem
    {
        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => $serial,
            'purchase_date' => $date,
        ]);
    }

    private function serial(EquipmentCatalog $catalog, string $year, int $n): string
    {
        return sprintf('IRNB-%s-BALL-%03d-%05d', $year, $catalog->id, $n);
    }

    #[Test]
    public function each_catalog_gets_its_own_gapless_series_in_purchase_order(): void
    {
        // The old shared counter: shirts 1, 2, then 5 after shorts took 3-4.
        $shirt1 = $this->unit($this->shirts, 'IRNB-2019-BALL-00001', '2019-01-01');
        $shirt2 = $this->unit($this->shirts, 'IRNB-2019-BALL-00002', '2019-01-02');
        $short1 = $this->unit($this->shorts, 'IRNB-2019-BALL-00003', '2019-01-03');
        $short2 = $this->unit($this->shorts, 'IRNB-2019-BALL-00004', '2019-01-04');
        // Added later but bought earlier: it comes first in its catalog.
        $shirt0 = $this->unit($this->shirts, 'IRNB-2019-BALL-00005', '2018-12-31');
        $shirt3 = $this->unit($this->shirts, 'IRNB-2020-BALL-00001', '2020-03-01');

        $this->artisan('equipment:renumber-serials')->assertSuccessful();

        $this->assertSame($this->serial($this->shirts, '2018', 1), $shirt0->fresh()->unique_identifier);
        $this->assertSame($this->serial($this->shirts, '2019', 1), $shirt1->fresh()->unique_identifier);
        $this->assertSame($this->serial($this->shirts, '2019', 2), $shirt2->fresh()->unique_identifier);
        $this->assertSame($this->serial($this->shirts, '2020', 1), $shirt3->fresh()->unique_identifier);
        $this->assertSame($this->serial($this->shorts, '2019', 1), $short1->fresh()->unique_identifier);
        $this->assertSame($this->serial($this->shorts, '2019', 2), $short2->fresh()->unique_identifier);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $unit = $this->unit($this->shirts, 'IRNB-2019-BALL-00007', '2019-01-01');

        $this->artisan('equipment:renumber-serials', ['--dry-run' => true])
            ->expectsOutputToContain('1 of 1 serials would change.')
            ->assertSuccessful();

        $this->assertSame('IRNB-2019-BALL-00007', $unit->fresh()->unique_identifier);
    }

    #[Test]
    public function count_tracked_lots_without_a_serial_are_left_alone_and_a_second_run_is_a_no_op(): void
    {
        $lot = EquipmentItem::create(['catalog_id' => $this->shorts->id, 'unique_identifier' => null, 'purchase_date' => '2019-01-01', 'quantity' => 10]);
        $unit = $this->unit($this->shirts, 'IRNB-2019-BALL-00009', '2019-01-01');

        $this->artisan('equipment:renumber-serials')->assertSuccessful();
        $this->artisan('equipment:renumber-serials')
            ->expectsOutputToContain('0 of 1 serials changed.')
            ->assertSuccessful();

        $this->assertNull($lot->fresh()->unique_identifier);
        $this->assertSame($this->serial($this->shirts, '2019', 1), $unit->fresh()->unique_identifier);
    }

    #[Test]
    public function new_units_continue_the_renumbered_series(): void
    {
        $this->unit($this->shirts, 'IRNB-2019-BALL-00004', '2019-01-01');
        $this->artisan('equipment:renumber-serials')->assertSuccessful();

        $next = new EquipmentItem(['catalog_id' => $this->shirts->id, 'purchase_date' => '2019-06-01']);
        app(SerialNumberService::class)->assign($next);

        $this->assertSame($this->serial($this->shirts, '2019', 2), $next->unique_identifier);
    }
}
