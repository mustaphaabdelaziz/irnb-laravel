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

class EquipmentSerialServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setClubShortName(?string $short): void
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = $short;
        $config->save();
    }

    private function catalogFor(string $categoryName, string $code): EquipmentCatalog
    {
        // updateOrCreate (not create): the equipment_categories migration
        // seeds default categories (including 'Balls'), so a plain create()
        // would collide with a unique-constraint violation on name.
        EquipmentCategory::updateOrCreate(['name' => $categoryName], ['code' => $code]);

        return EquipmentCatalog::create(['name' => $categoryName.' item', 'category' => $categoryName]);
    }

    private function makeItem(EquipmentCatalog $catalog, string $purchaseDate): EquipmentItem
    {
        $item = new EquipmentItem(['catalog_id' => $catalog->id, 'purchase_date' => $purchaseDate]);
        app(SerialNumberService::class)->assign($item);

        return $item;
    }

    /** The serial of a unit of $catalog: CLUB-YEAR-CODE-CCC-NNNNN. */
    private function serial(string $club, string $year, string $code, EquipmentCatalog $catalog, int $n): string
    {
        return sprintf('%s-%s-%s-%03d-%05d', $club, $year, $code, $catalog->id, $n);
    }

    #[Test]
    public function it_generates_a_serial_in_the_expected_format(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $item = $this->makeItem($catalog, '2026-05-01');

        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $catalog, 1), $item->unique_identifier);
        $this->assertMatchesRegularExpression('/^IRNB-2026-BALL-\d{3,}-00001$/', $item->unique_identifier);
    }

    #[Test]
    public function it_increments_the_counter_within_the_same_year_and_catalog(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $first = $this->makeItem($catalog, '2026-05-01');
        $second = $this->makeItem($catalog, '2026-09-15');

        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $catalog, 1), $first->unique_identifier);
        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $catalog, 2), $second->unique_identifier);
    }

    #[Test]
    public function the_counter_resets_per_year(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $y2026 = $this->makeItem($catalog, '2026-05-01');
        $y2027 = $this->makeItem($catalog, '2027-01-02');

        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $catalog, 1), $y2026->unique_identifier);
        $this->assertSame($this->serial('IRNB', '2027', 'BALL', $catalog, 1), $y2027->unique_identifier);
    }

    #[Test]
    public function distinct_categories_do_not_share_a_counter(): void
    {
        $this->setClubShortName('IRNB');
        $balls = $this->catalogFor('Balls', 'BALL');
        $goals = $this->catalogFor('Goals', 'GOAL');

        $ball = $this->makeItem($balls, '2026-05-01');
        $goal = $this->makeItem($goals, '2026-05-01');

        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $balls, 1), $ball->unique_identifier);
        $this->assertSame($this->serial('IRNB', '2026', 'GOAL', $goals, 1), $goal->unique_identifier);
    }

    #[Test]
    public function catalogs_of_the_same_category_and_year_each_count_from_one(): void
    {
        // The counter used to be shared by the whole category: a catalog's
        // units went 00017, 00018, then 00055 once other catalogs of the
        // category had taken the numbers between.
        $this->setClubShortName('IRNB');
        $shirts = $this->catalogFor('Balls', 'BALL');
        $shorts = EquipmentCatalog::create(['name' => 'Shorts', 'category' => 'Balls']);

        $shirt1 = $this->makeItem($shirts, '2026-05-01');
        $short1 = $this->makeItem($shorts, '2026-05-01');
        $short2 = $this->makeItem($shorts, '2026-05-01');
        $shirt2 = $this->makeItem($shirts, '2026-05-01');

        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $shirts, 1), $shirt1->unique_identifier);
        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $shirts, 2), $shirt2->unique_identifier);
        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $shorts, 1), $short1->unique_identifier);
        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $shorts, 2), $short2->unique_identifier);
    }

    #[Test]
    public function the_club_abbreviation_falls_back_when_short_name_is_missing(): void
    {
        // website_configs.club_short_name is NOT NULL at the DB level (default
        // 'SC'), so an empty string is how "missing" is actually represented;
        // the service treats null/'' identically via its (string) cast.
        $this->setClubShortName('');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $item = $this->makeItem($catalog, '2026-05-01');

        $this->assertSame($this->serial('CLUB', '2026', 'BALL', $catalog, 1), $item->unique_identifier);
    }

    #[Test]
    public function preview_next_returns_the_next_serial_without_persisting(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');
        $this->makeItem($catalog, '2026-05-01');

        $service = app(SerialNumberService::class);
        $preview = $service->previewNext($catalog->id, '2026-07-01');

        $this->assertSame($this->serial('IRNB', '2026', 'BALL', $catalog, 2), $preview);
        $this->assertSame(1, EquipmentItem::count());
    }
}
