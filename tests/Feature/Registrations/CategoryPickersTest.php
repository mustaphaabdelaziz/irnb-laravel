<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class CategoryPickersTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
    }

    #[Test]
    public function the_calendar_defaults_to_the_first_active_category_of_the_branch(): void
    {
        $this->u15->update(['is_active' => false]);
        $next = Category::create(['name' => 'Next', 'branch_id' => $this->swim->id]);

        $this->get(route('attendance.index', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', $next->id)->where('branchId', $this->swim->id));
    }

    #[Test]
    public function a_category_of_another_branch_in_the_url_falls_back_to_the_branchs_own(): void
    {
        $this->get(route('attendance.index', ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', $this->u15->id));
    }

    #[Test]
    public function a_branch_with_no_categories_has_no_default(): void
    {
        $this->senior->delete();

        $this->get(route('attendance.index', ['branch_id' => $this->foot->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', null));
    }

    #[Test]
    public function the_calendar_sends_the_owner_with_each_category(): void
    {
        $this->get(route('attendance.index', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->senior->id)['branch_id'] === $this->foot->id));
    }

    #[Test]
    public function report_pages_list_only_the_branchs_categories_inactive_included(): void
    {
        $old = Category::create(['name' => 'Old', 'branch_id' => $this->swim->id, 'is_active' => false]);

        foreach (['attendance.alerts', 'attendance.injuries', 'attendance.ranking', 'attendance.stats'] as $name) {
            $this->get(route($name, ['branch_id' => $this->swim->id]))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('categories', fn ($list) => collect($list)->pluck('id')->sort()->values()->all() === collect([$this->u15->id, $old->id])->sort()->values()->all()));
        }
    }

    #[Test]
    public function a_report_page_ignores_a_category_of_another_branch(): void
    {
        $this->get(route('attendance.stats', ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categoryId', null));
    }

    #[Test]
    public function the_stats_table_shows_only_the_branchs_categories(): void
    {
        $this->get(route('attendance.stats', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categoryRows', fn ($rows) => collect($rows)->pluck('category_id')->all() === [$this->u15->id]));
    }

    #[Test]
    public function the_settings_page_sends_the_owner_with_each_category(): void
    {
        $this->get(route('attendance.settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->u15->id)['branch_id'] === $this->swim->id));
    }

    #[Test]
    public function the_team_form_lists_its_branchs_categories_plus_the_stored_one(): void
    {
        $team = \App\Models\Team::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'name' => 'A',
            'default_game_format_id' => \App\Models\GameFormat::create([
                'branch_id' => $this->swim->id, 'sport' => 'other', 'name' => 'F', 'players_on_field' => 1, 'max_bench' => 0, 'max_called_up' => 1,
                'periods_count' => 1, 'period_minutes' => 1, 'period_label' => 'half', 'scoring' => 'goals', 'sanction_types' => [],
            ])->id,
        ]);

        $this->get(route('teams.show', $team))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->pluck('id')->sort()->values()->all() === collect([$this->senior->id, $this->u15->id])->sort()->values()->all()));
    }

    #[Test]
    public function the_subscription_form_gets_each_categorys_sport_to_label_it(): void
    {
        $this->get(route('subscriptions.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->u15->id)['branch']['name'] === 'Swimming'));
    }

    #[Test]
    public function the_caisse_page_sends_the_owner_with_each_category(): void
    {
        $this->get(route('finance.registers.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->u15->id)['branch_id'] === $this->swim->id));
    }
}
