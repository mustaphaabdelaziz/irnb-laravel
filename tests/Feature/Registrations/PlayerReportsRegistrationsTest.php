<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerReportsRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $this->travelTo('2026-05-14 10:00:00');
    }

    /** @return array<string, mixed> */
    private function report(string $key, array $query = []): array
    {
        $props = [];
        $this->get(route('reports.view', ['players', $key, ...$query]))->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function swimId(Player $player): void
    {
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);
    }

    #[Test]
    public function directory_filters_on_one_registration_and_shows_its_values(): void
    {
        $this->swimId($this->bothActive());

        $this->assertCount(0, $this->report('directory', ['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]])['rows']['data']);

        $row = $this->report('directory', ['branch_id' => [$this->swim->id]])['rows']['data'][0];
        $this->assertSame('SW-2020-00001', $row['membership_id']);
        $this->assertSame('U15', $row['category']);
    }

    #[Test]
    public function directory_with_no_branch_shows_main_values_one_row_per_person(): void
    {
        $this->bothActive();

        $rows = $this->report('directory')['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('Senior', $rows[0]['category']);
    }

    #[Test]
    public function directory_category_chart_counts_both_and_says_so(): void
    {
        $this->bothActive();

        $props = $this->report('directory');
        $bars = collect($props['charts'][0]['stats'])->pluck('count', 'label')->all();

        $this->assertEquals(['Senior' => 1, 'U15' => 1], $bars);
        $this->assertSame('chart_note.bars_can_exceed_total', $props['note']);
        $this->assertNull($this->report('directory', ['branch_id' => [$this->swim->id]])['note']);
        $this->assertSame(1, $props['summary'][0]['value']);
    }

    #[Test]
    public function directory_status_is_the_registrations_with_a_branch_and_the_persons_without(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertCount(1, $this->report('directory', ['branch_id' => [$this->foot->id], 'status_id' => [$left]])['rows']['data']);
        $this->assertCount(0, $this->report('directory', ['status_id' => [$left]])['rows']['data']);
    }

    #[Test]
    public function movement_left_means_everywhere_club_wide_and_that_branch_with_one(): void
    {
        $this->leftFootball();
        $query = ['movement' => 'left', 'year' => 2026];

        $this->assertCount(0, $this->report('movement', $query)['rows']['data']);

        $row = $this->report('movement', [...$query, 'branch_id' => [$this->foot->id]])['rows']['data'][0];
        $this->assertSame($this->leaveDate, $row['left_at']);
        $this->assertSame('Senior', $row['category']);
        $this->assertCount(0, $this->report('movement', [...$query, 'branch_id' => [$this->swim->id]])['rows']['data']);
    }

    #[Test]
    public function movement_left_everywhere_is_listed_club_wide(): void
    {
        $this->leftBoth();

        $this->assertCount(1, $this->report('movement', ['movement' => 'left', 'year' => 2026])['rows']['data']);
    }

    #[Test]
    public function movement_charts_group_leave_years_on_the_shown_branch(): void
    {
        $this->leftFootball();

        $props = $this->report('movement', ['movement' => 'left', 'year' => 2026, 'branch_id' => [$this->foot->id]]);

        $this->assertSame([['key' => '2026', 'label' => '2026', 'count' => 1]], $props['charts'][1]['stats']);
    }

    #[Test]
    public function demographics_table_counts_registrations_and_the_total_counts_people(): void
    {
        $this->bothActive();
        $this->noRegistration();

        $props = $this->report('demographics');
        $byCategory = collect($props['rows']['data'])->pluck('total', 'category')->all();

        $this->assertEquals(['Senior' => 2, 'U15' => 1], $byCategory);
        $this->assertSame(2, $props['totals']['total']);
        $this->assertSame(2, $props['summary'][0]['value']);
        $this->assertSame('chart_note.bars_can_exceed_total', $props['note']);
    }

    #[Test]
    public function demographics_of_one_branch_uses_its_categories(): void
    {
        $this->bothActive();

        $byCategory = collect($this->report('demographics', ['branch_id' => [$this->swim->id]])['rows']['data'])->pluck('total', 'category')->all();

        $this->assertSame(['U15' => 1], $byCategory);
    }

    #[Test]
    public function demographics_leaves_out_left_rows_unless_left_is_picked(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->leftBoth();

        $this->assertSame(['U15' => 1], collect($this->report('demographics')['rows']['data'])->pluck('total', 'category')->all());
        $this->assertEquals(['Senior' => 1, 'U15' => 1], collect($this->report('demographics', ['status_id' => [$left]])['rows']['data'])->pluck('total', 'category')->all());
    }

    #[Test]
    public function birthdays_show_the_one_branchs_category(): void
    {
        $player = $this->bothActive();
        $player->update(['birthdate' => '2010-05-20']);

        $this->assertSame('U15', $this->report('birthdays', ['month' => 5, 'branch_id' => [$this->swim->id]])['rows']['data'][0]['category']);
        $this->assertSame('Senior', $this->report('birthdays', ['month' => 5])['rows']['data'][0]['category']);
    }

    #[Test]
    public function directory_category_chart_leaves_out_the_other_categories_bars(): void
    {
        $this->bothActive();
        $senior = ['category_id' => [$this->senior->id]];

        $bars = fn (array $q) => collect($this->report('directory', $q)['charts'][0]['stats'])->pluck('count', 'label')->all();

        $this->assertEquals(['Senior' => 1], $bars($senior));
        $this->assertEquals(['Senior' => 1], $bars([...$senior, 'branch_id' => [$this->foot->id, $this->swim->id]]));
    }
}
