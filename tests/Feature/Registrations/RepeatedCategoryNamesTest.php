<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\GameFormat;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Reports\Players\PlayerDirectory;
use App\Services\Player\RegistrationCounts;
use App\Services\Player\RegistrationService;
use App\Support\CategoryLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class RepeatedCategoryNamesTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private Category $swimSenior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->swimSenior = Category::create(['name' => 'Senior', 'branch_id' => $this->swim->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function team(Category $category): Team
    {
        $format = GameFormat::create([
            'branch_id' => $category->branch_id, 'sport' => 'other', 'name' => 'F'.$category->id, 'players_on_field' => 1, 'max_bench' => 0, 'max_called_up' => 1,
            'periods_count' => 1, 'period_minutes' => 1, 'period_label' => 'half', 'scoring' => 'goals', 'sanction_types' => [],
        ]);

        return Team::create(['branch_id' => $category->branch_id, 'category_id' => $category->id, 'name' => 'First', 'default_game_format_id' => $format->id]);
    }

    #[Test]
    public function the_players_page_gets_each_categorys_sport(): void
    {
        $this->get(route('players.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories', fn ($list) => collect($list)->firstWhere('id', $this->swimSenior->id)['branch']['name'] === 'Swimming'));
    }

    #[Test]
    public function report_filters_name_the_sport_when_the_name_repeats(): void
    {
        $filters = collect(app(PlayerDirectory::class)->filters());
        $category = $filters->firstWhere('key', 'category_id');

        $labels = collect($category->options ?? $category['options'])->pluck('label');

        $this->assertContains('Senior · Swimming', $labels);
        $this->assertContains('Senior · Football', $labels);
        $this->assertContains('U15', $labels);
    }

    #[Test]
    public function a_team_label_names_the_sport_only_when_the_category_repeats(): void
    {
        $labels = CategoryLabels::make();

        $this->assertSame('First · Senior · Swimming', $this->team($this->swimSenior)->labelWith($labels));
        $this->assertSame('First · U15', $this->team($this->u15)->labelWith($labels));
    }

    #[Test]
    public function a_team_with_a_name_ending_in_an_arabic_letter_is_not_cut(): void
    {
        $team = $this->team($this->u15);
        $team->name = 'نشاط';

        $this->assertSame('نشاط · U15', $team->labelWith(CategoryLabels::make()));
    }

    #[Test]
    public function category_counts_keep_same_named_categories_apart(): void
    {
        $service = app(RegistrationService::class);
        $service->register(Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'A', 'join_year' => 2020]), ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $service->register(Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'B', 'join_year' => 2020]), ['branch_id' => $this->swim->id, 'category_id' => $this->swimSenior->id]);

        $names = RegistrationCounts::byCategory(Player::query())->pluck('name');

        $this->assertContains('Senior · Football', $names);
        $this->assertContains('Senior · Swimming', $names);
    }
}
