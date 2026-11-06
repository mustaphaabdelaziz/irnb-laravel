<?php

namespace Tests\Feature\Registrations;

use App\Models\PlayerStatus;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\MemberStats;
use App\Services\Dashboard\ModuleStats;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DashboardRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function members(array $query = []): array
    {
        return app(MemberStats::class)->get(DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', ['range' => 'all', ...$query])));
    }

    private function tile(array $data, string $key): mixed
    {
        return collect($data['summary'])->firstWhere('key', $key)['value'];
    }

    private function bars(array $rows): array
    {
        return collect($rows)->pluck('count', 'name')->all();
    }

    #[Test]
    public function total_members_counts_a_person_once_and_the_category_chart_both_bars(): void
    {
        $this->bothActive();

        $data = $this->members();

        $this->assertSame(1, $this->tile($data, 'total_members'));
        $this->assertEquals(['Senior' => 1, 'U15' => 1], $this->bars($data['byCategory']));
    }

    #[Test]
    public function one_branch_shows_its_own_categories(): void
    {
        $this->bothActive();

        $this->assertSame(['U15' => 1], $this->bars($this->members(['branch' => (string) $this->swim->id])['byCategory']));
    }

    #[Test]
    public function a_left_registration_leaves_the_category_chart_but_joins_left_by_category(): void
    {
        $this->leftFootball();

        $data = $this->members();

        $this->assertSame(['U15' => 1], $this->bars($data['byCategory']));
        $this->assertSame(['Senior' => 1], $this->bars($data['leftByCategory']));
    }

    #[Test]
    public function the_left_tile_means_left_everywhere_club_wide_and_that_branch_with_one(): void
    {
        $this->leftFootball();

        $this->assertSame(0, $this->tile($this->members(), 'left'));
        $this->assertSame(1, $this->tile($this->members(['branch' => (string) $this->foot->id]), 'left'));
        $this->assertSame(0, $this->tile($this->members(['branch' => (string) $this->swim->id]), 'left'));

        $this->leftBoth();
        $this->assertSame(1, $this->tile($this->members(), 'left'));
    }

    #[Test]
    public function status_is_overall_club_wide_and_the_registrations_with_a_branch(): void
    {
        $this->leftFootball();
        $names = PlayerStatus::all()->mapWithKeys(fn (PlayerStatus $status) => [$status->code => $status->localized_name]);

        $this->assertSame([$names['registered'] => 1], $this->bars($this->members()['byStatus']));
        $this->assertSame([$names['left'] => 1], $this->bars($this->members(['branch' => (string) $this->foot->id])['byStatus']));
    }

    #[Test]
    public function the_growth_chart_counts_a_branch_leaver_in_that_branch_only(): void
    {
        $this->leftFootball();

        $this->assertSame(0.0, array_sum($this->members()['growth']['left']));
        $this->assertSame(1.0, array_sum($this->members(['branch' => (string) $this->foot->id])['growth']['left']));
    }

    #[Test]
    public function a_player_with_no_registration_counts_on_their_own_columns(): void
    {
        $this->noRegistration();

        $data = $this->members();

        $this->assertSame(1, $this->tile($data, 'total_members'));
        $this->assertSame(['Senior' => 1], $this->bars($data['byCategory']));
    }

    #[Test]
    public function the_module_strip_counts_people_and_left_everywhere(): void
    {
        $this->bothActive('A');
        $this->leftFootball('B');
        $this->leftBoth('C');
        $tiles = collect(app(ModuleStats::class)->players())->keyBy('key');

        $this->assertSame(2, $tiles['players_active']['value']);
    }

    #[Test]
    public function a_windowed_range_counts_only_the_branch_leavers_inside_it(): void
    {
        $inside = $this->bothActive('Inside');
        $outside = $this->bothActive('Outside');
        app(RegistrationService::class)->leave($inside, [$this->foot->id], '2026-05-10');
        app(RegistrationService::class)->leave($outside, [$this->foot->id], '2026-03-15');

        $data = $this->members(['range' => 'month', 'branch' => (string) $this->foot->id]);

        $this->assertSame(1, $this->tile($data, 'left'));
        $this->assertSame(['Senior' => 1], $this->bars($data['leftByCategory']));
    
    }
}
