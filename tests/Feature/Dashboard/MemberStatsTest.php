<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\PlayerSubscription;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\MemberStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function members(array $query = []): array
    {
        return app(MemberStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query)),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function summary(array $query = []): array
    {
        return collect($this->members($query)['summary'])->keyBy('key')->all();
    }

    private function player(array $attributes = [], ?string $joinedAt = null): Player
    {
        static $n = 0;
        $n++;

        $player = Player::create(array_merge([
            'membership_id' => str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'firstname' => 'Player',
            'lastname' => "Number{$n}",
        ], $attributes));

        if ($joinedAt !== null) {
            $player->forceFill(['created_at' => $joinedAt])->save();
        }

        return $player->refresh();
    }

    #[Test]
    public function new_members_counts_only_the_window(): void
    {
        $this->player([], '2026-05-02 09:00:00');
        $this->player([], '2026-05-09 09:00:00');
        $this->player([], '2026-01-09 09:00:00');

        $this->assertSame(2, $this->summary()['new_members']['value']);
    }

    #[Test]
    public function archived_members_are_counted_separately_and_left_out_of_the_rest(): void
    {
        $this->player(['archived' => true], '2026-05-02 09:00:00');
        $this->player([], '2026-05-03 09:00:00');

        $summary = $this->summary();

        $this->assertSame(1, $summary['new_members']['value']);
        $this->assertSame(1, $summary['archived']['value']);
    }

    #[Test]
    public function median_debt_is_the_middle_value_not_the_mean(): void
    {
        // 0, 100, 5 000 — the mean would be 1 700, the median is 100.
        $this->player(['outstanding_debt' => 0]);
        $this->player(['outstanding_debt' => 100]);
        $this->player(['outstanding_debt' => 5000]);

        $this->assertSame(100.0, $this->summary()['median_debt']['value']);
    }

    #[Test]
    public function median_debt_of_an_even_population_averages_the_middle_pair(): void
    {
        $this->player(['outstanding_debt' => 100]);
        $this->player(['outstanding_debt' => 300]);

        $this->assertSame(200.0, $this->summary()['median_debt']['value']);
    }

    #[Test]
    public function median_debt_is_null_when_there_are_no_members(): void
    {
        $this->assertNull($this->summary()['median_debt']['value']);
    }

    #[Test]
    public function renewal_rate_is_last_years_members_who_came_back(): void
    {
        $stayed = $this->player();
        $left = $this->player();

        foreach ([$stayed, $left] as $player) {
            PlayerSubscription::create([
                'player_id' => $player->id, 'year' => 2025, 'amount_owed' => 1000, 'amount_paid' => 1000,
            ]);
        }
        PlayerSubscription::create([
            'player_id' => $stayed->id, 'year' => 2026, 'amount_owed' => 1000, 'amount_paid' => 0,
        ]);

        $this->assertSame(50.0, $this->summary()['renewal_rate']['value']);
    }

    #[Test]
    public function renewal_rate_is_null_without_a_previous_year_to_measure(): void
    {
        $this->player();

        $this->assertNull($this->summary()['renewal_rate']['value']);
    }

    #[Test]
    public function growth_returns_twelve_months_of_joins_and_a_running_total(): void
    {
        $this->player([], '2026-04-02 09:00:00');
        $this->player([], '2026-05-02 09:00:00');
        $this->player([], '2026-05-03 09:00:00');

        $growth = $this->members()['growth'];

        $this->assertCount(12, $growth['labels']);
        $this->assertSame(1.0, $growth['joined'][10]);
        $this->assertSame(2.0, $growth['joined'][11]);
        // The running total accumulates rather than repeating the month's count.
        $this->assertSame(3.0, $growth['cumulative'][11]);
    }

    #[Test]
    public function members_split_by_category_with_the_localised_name(): void
    {
        $category = Category::create(['name' => 'Senior', 'name_fr' => 'Séniors', 'name_ar' => 'أكابر']);
        $this->player(['category_id' => $category->id]);
        $this->player();

        app()->setLocale('fr');
        $rows = collect($this->members()['byCategory']);

        $this->assertSame(1, $rows->firstWhere('name', 'Séniors')['count']);
        // The uncategorised row is a key, not an English word.
        $this->assertSame(1, $rows->firstWhere('labelKey', 'uncategorised')['count']);
    }

    #[Test]
    public function members_split_by_status(): void
    {
        $status = PlayerStatus::create(['name' => 'Injured', 'name_fr' => 'Blessé']);
        $this->player(['status_id' => $status->id]);

        app()->setLocale('fr');

        $this->assertSame(1, collect($this->members()['byStatus'])->firstWhere('name', 'Blessé')['count']);
    }

    #[Test]
    public function age_bands_come_from_the_birthdate(): void
    {
        $this->player(['birthdate' => '2016-01-01']); // 10 -> under 12
        $this->player(['birthdate' => '2012-01-01']); // 14 -> 12-15
        $this->player(['birthdate' => '1990-01-01']); // 36 -> 36+
        $this->player(['birthdate' => null]);         // unknown

        $bands = collect($this->members()['byAge'])->keyBy('band');

        $this->assertSame(1, $bands['u12']['count']);
        $this->assertSame(1, $bands['12_15']['count']);
        $this->assertSame(1, $bands['36_plus']['count']);
        $this->assertSame(1, $bands['unknown']['count']);
    }

    #[Test]
    public function age_bands_always_return_every_band_so_the_chart_keeps_its_shape(): void
    {
        $this->assertSame(
            ['u12', '12_15', '16_18', '19_25', '26_35', '36_plus', 'unknown'],
            collect($this->members()['byAge'])->pluck('band')->all(),
        );
    }

    #[Test]
    public function debt_bands_group_members_by_what_they_owe(): void
    {
        $this->player(['outstanding_debt' => 0]);
        $this->player(['outstanding_debt' => 2000]);
        $this->player(['outstanding_debt' => 12000]);
        $this->player(['outstanding_debt' => 40000]);

        $bands = collect($this->members()['debtBands'])->keyBy('band');

        $this->assertSame(1, $bands['none']['count']);
        $this->assertSame(1, $bands['under_5k']['count']);
        $this->assertSame(1, $bands['5k_20k']['count']);
        $this->assertSame(1, $bands['over_20k']['count']);
    }

    #[Test]
    public function the_split_reports_students_workers_and_gender(): void
    {
        $this->player(['is_student' => true, 'gender' => 'Male']);
        $this->player(['is_student' => false, 'gender' => 'Female']);
        $this->player(['is_student' => true, 'gender' => 'Male']);

        $split = $this->members()['split'];

        $this->assertSame(2, $split['students']);
        $this->assertSame(1, $split['workers']);
        $this->assertSame(2, $split['male']);
        $this->assertSame(1, $split['female']);
    }

    #[Test]
    public function top_cities_are_ranked_and_capped(): void
    {
        foreach (range(1, 3) as $i) {
            $this->player(['city' => 'Algiers']);
        }
        $this->player(['city' => 'Oran']);

        $cities = $this->members()['topCities'];

        $this->assertSame('Algiers', $cities[0]['name']);
        $this->assertSame(3, $cities[0]['count']);
        $this->assertLessThanOrEqual(6, count($cities));
    }

    #[Test]
    public function the_branch_filter_isolates_members(): void
    {
        $north = Branch::create(['name' => 'North']);
        $this->player()->branches()->attach($north->id);
        $this->player();

        $this->assertSame(1, $this->summary(['branch' => (string) $north->id])['total_members']['value']);
        $this->assertSame(2, $this->summary()['total_members']['value']);
    }

    #[Test]
    public function an_empty_database_is_safe(): void
    {
        $members = $this->members();

        $this->assertSame([], $members['byCategory']);
        $this->assertSame([], $members['topCities']);
        $this->assertCount(12, $members['growth']['labels']);
        $this->assertNull($this->summary()['median_debt']['value']);
        $this->assertNull($this->summary()['renewal_rate']['value']);
    }

    #[Test]
    public function academic_block_averages_each_students_latest_gpa(): void
    {
        $a = $this->player(['is_student' => true]);
        $a->academicRecords()->create(['academic_year' => 2024, 'period' => 'S1', 'gpa' => 6]);
        $a->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 14]); // latest 14
        $b = $this->player(['is_student' => true]);
        $b->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 9]);  // at risk
        $this->player(['is_student' => true]);                                                     // missing
        $w = $this->player(['is_student' => false]);
        $w->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 2]);  // ignored

        $this->assertSame(
            ['students' => 3, 'average' => 11.5, 'at_risk' => 1, 'missing' => 1],
            $this->members()['academic'],
        );
    }

    #[Test]
    public function academic_average_is_null_without_any_gpa(): void
    {
        $this->player(['is_student' => true]);

        $this->assertNull($this->members()['academic']['average']);
    }
}
