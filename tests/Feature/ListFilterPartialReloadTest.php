<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * List pages filter via Inertia partial reloads (useListFilters.js): the
 * search box asks only for the filter-dependent props. These lock in that the
 * full visit still carries the lookup lists and that a partial reload returns
 * filtered rows + stats without them.
 */
class ListFilterPartialReloadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Player::create([
            'membership_id' => '9100000001',
            'firstname' => 'عبد العزيز',
            'lastname' => 'بن علي',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);

        Player::create([
            'membership_id' => '9100000002',
            'firstname' => 'Yacine',
            'lastname' => 'Boudiaf',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);
    }

    #[Test]
    public function players_full_visit_includes_lookup_lists(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Players/Index')
                ->has('categories')
                ->has('branches')
                ->has('positions')
                ->has('playerStatuses')
                ->has('documentTypes')
                ->has('players.data', 2));
    }

    #[Test]
    public function players_search_reload_returns_rows_and_stats_without_lookups(): void
    {
        $only = ['players', 'filters', 'categoryStats', 'statusStats', 'positionStats', 'ageStats'];

        $this->actingAs($this->admin())
            ->get(route('players.index', ['search' => 'عبد العزيز']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->reloadOnly($only, fn (AssertableInertia $reload) => $reload
                    ->has('players.data', 1)
                    ->where('players.data.0.membership_id', '9100000001')
                    ->where('filters.search', 'عبد العزيز')
                    ->has('categoryStats')
                    ->has('ageStats')
                    ->missing('categories')
                    ->missing('branches')
                    ->missing('positions')
                    ->missing('playerStatuses')
                    ->missing('documentTypes')));
    }
}
