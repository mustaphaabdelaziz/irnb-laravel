<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four stat doughnuts were raw queries hardcoded to `archived = false`,
 * so filtering the list left them unchanged — and the Archived view still
 * reported active players. They must describe the same population the list
 * is showing.
 */
class PlayerStatsFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600100;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(array $attributes = []): Player
    {
        return Player::create(array_merge([
            'firstname' => 'P', 'lastname' => 'X',
            'membership_id' => (string) ++$this->membership,
            'join_year' => 2026,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function props(array $query = []): array
    {
        return $this->actingAs($this->admin())
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function total(array $stats): int
    {
        return collect($stats)->sum('count');
    }

    #[Test]
    public function filtering_by_branch_narrows_every_statistic(): void
    {
        $football = Branch::create(['name' => 'Football', 'name_en' => 'Football']);
        $basket = Branch::create(['name' => 'Basketball', 'name_en' => 'Basketball']);

        $this->player()->branches()->sync([$football->id]);
        $this->player()->branches()->sync([$basket->id]);
        $this->player()->branches()->sync([$basket->id]);

        $props = $this->props(['branch_id' => $football->id]);

        $this->assertCount(1, $props['players']['data']);
        $this->assertSame(1, $this->total($props['categoryStats']), 'category chart must follow the filter');
        $this->assertSame(1, $this->total($props['statusStats']), 'status chart must follow the filter');
        $this->assertSame(1, $this->total($props['positionStats']), 'position chart must follow the filter');
        $this->assertSame(1, $this->total($props['ageStats']), 'age chart must follow the filter');
    }

    #[Test]
    public function the_archived_view_reports_archived_players(): void
    {
        $this->player(['archived' => true]);
        $this->player();
        $this->player();

        $props = $this->props(['archived' => 1]);

        $this->assertCount(1, $props['players']['data']);
        $this->assertSame(1, $this->total($props['statusStats']), 'archived view must not report active players');
    }

    #[Test]
    public function a_search_narrows_the_statistics(): void
    {
        $this->player(['firstname' => 'Karim']);
        $this->player(['firstname' => 'Yacine']);

        $props = $this->props(['search' => 'Karim']);

        $this->assertSame(1, $this->total($props['statusStats']));
    }

    #[Test]
    public function a_chart_still_shows_its_own_other_options_when_filtered_by_that_dimension(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);

        $this->player(['category_id' => $u15->id]);
        $this->player(['category_id' => $u17->id]);

        $props = $this->props(['category_id' => $u15->id]);

        // The list narrows...
        $this->assertCount(1, $props['players']['data']);
        // ...but the category chart must keep offering U17, or the user can
        // never switch category by clicking a slice.
        $this->assertCount(2, $props['categoryStats'], 'the chart must not collapse to its own filter');
    }

    #[Test]
    public function filtering_by_one_dimension_still_narrows_the_others(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);
        $gk = Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK']);

        $this->player(['category_id' => $u15->id, 'position_id' => $gk->id]);
        $this->player(['category_id' => $u17->id, 'position_id' => $gk->id]);

        $props = $this->props(['category_id' => $u15->id]);

        // The position chart is a different dimension, so it must narrow.
        $this->assertSame(1, $this->total($props['positionStats']));
    }

    #[Test]
    public function the_age_buckets_are_computed_for_the_filtered_set(): void
    {
        $retired = PlayerStatus::where('name', 'معتزل')->first();

        $this->player(['birthdate' => now()->subYears(15)->toDateString(), 'status_id' => $retired->id]);
        $this->player(['birthdate' => now()->subYears(35)->toDateString()]);

        $props = $this->props(['status' => $retired->id]);

        $this->assertSame(1, $this->total($props['ageStats']));
        $this->assertSame('10-19', collect($props['ageStats'])->first()['bucket']);
    }
}
