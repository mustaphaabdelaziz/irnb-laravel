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

    #[Test]
    public function it_generates_a_serial_in_the_expected_format(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $item = $this->makeItem($catalog, '2026-05-01');

        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function it_increments_the_counter_within_the_same_year_and_category(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $first = $this->makeItem($catalog, '2026-05-01');
        $second = $this->makeItem($catalog, '2026-09-15');

        $this->assertSame('IRNB-2026-BALL-00001', $first->unique_identifier);
        $this->assertSame('IRNB-2026-BALL-00002', $second->unique_identifier);
    }

    #[Test]
    public function the_counter_resets_per_year(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');

        $y2026 = $this->makeItem($catalog, '2026-05-01');
        $y2027 = $this->makeItem($catalog, '2027-01-02');

        $this->assertSame('IRNB-2026-BALL-00001', $y2026->unique_identifier);
        $this->assertSame('IRNB-2027-BALL-00001', $y2027->unique_identifier);
    }

    #[Test]
    public function distinct_categories_do_not_share_a_counter(): void
    {
        $this->setClubShortName('IRNB');
        $balls = $this->catalogFor('Balls', 'BALL');
        $goals = $this->catalogFor('Goals', 'GOAL');

        $ball = $this->makeItem($balls, '2026-05-01');
        $goal = $this->makeItem($goals, '2026-05-01');

        $this->assertSame('IRNB-2026-BALL-00001', $ball->unique_identifier);
        $this->assertSame('IRNB-2026-GOAL-00001', $goal->unique_identifier);
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

        $this->assertSame('CLUB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function preview_next_returns_the_next_serial_without_persisting(): void
    {
        $this->setClubShortName('IRNB');
        $catalog = $this->catalogFor('Balls', 'BALL');
        $this->makeItem($catalog, '2026-05-01');

        $service = app(SerialNumberService::class);
        $preview = $service->previewNext($catalog->id, '2026-07-01');

        $this->assertSame('IRNB-2026-BALL-00002', $preview);
        $this->assertSame(1, EquipmentItem::count());
    }
}
