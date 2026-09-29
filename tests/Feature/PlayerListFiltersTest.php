<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(array $attributes): Player
    {
        static $sequence = 0;
        $sequence++;

        return Player::create([
            'membership_id' => '2024'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'join_year' => 2024,
            'is_student' => true,
            ...$attributes,
        ]);
    }

    #[Test]
    public function a_player_can_be_saved_without_a_skill_level(): void
    {
        $this->actingAs($this->admin())
            ->post(route('players.store'), ['firstname' => 'Ali', 'lastname' => 'Benali', 'skill_level' => null])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $player = Player::firstOrFail();
        $this->assertNull($player->skill_level);

        $this->put(route('players.update', $player), ['firstname' => 'Ali', 'lastname' => 'Benali', 'skill_level' => null])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_list_filters_by_an_exact_family_name(): void
    {
        $this->player(['firstname' => 'Ali', 'lastname' => 'Ali']);
        $this->player(['firstname' => 'Omar', 'lastname' => 'Benali']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Khelifi']);

        // Picked from the list: "Ali" must not also bring in "Benali".
        $this->actingAs($this->admin())
            ->get(route('players.index', ['lastname' => 'Ali']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('players.data', 1)
                ->where('players.data.0.firstname', 'Ali'));
    }

    #[Test]
    public function the_family_list_and_chart_count_players_per_family(): void
    {
        foreach (['Benali', 'Benali', 'Benali', 'Khelifi', 'Khelifi', 'Saadi'] as $i => $lastname) {
            $this->player(['firstname' => "P{$i}", 'lastname' => $lastname]);
        }
        $this->player(['firstname' => 'Gone', 'lastname' => 'Archived', 'archived' => true]);

        $this->actingAs($this->admin())
            ->get(route('players.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The picker lists every active family, alphabetically, with its size.
                ->where('familyNames', [
                    ['name' => 'Benali', 'count' => 3],
                    ['name' => 'Khelifi', 'count' => 2],
                    ['name' => 'Saadi', 'count' => 1],
                ])
                ->where('familyStats.0', ['name' => 'Benali', 'count' => 3])
                ->has('familyStats', 3));
    }

    #[Test]
    public function the_family_chart_folds_small_families_into_others(): void
    {
        foreach (range(1, 10) as $i) {
            $this->player(['firstname' => "P{$i}", 'lastname' => "Family{$i}"]);
        }

        $this->actingAs($this->admin())
            ->get(route('players.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Top 8 families, then one "others" slice holding the last 2.
                ->has('familyStats', 9)
                ->where('familyStats.8', ['name' => null, 'count' => 2, 'others' => true]));
    }

    #[Test]
    public function the_list_filters_by_blood_group(): void
    {
        $this->player(['firstname' => 'Ali', 'health_blood_group_rhesus' => 'A+']);
        $this->player(['firstname' => 'Sami', 'health_blood_group_rhesus' => 'A-']);

        $this->actingAs($this->admin())
            ->get(route('players.index', ['blood_group' => 'A+']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('players.data', 1)
                ->where('players.data.0.firstname', 'Ali'));
    }

    #[Test]
    public function a_player_needs_a_last_name_and_a_branch_once_branches_exist(): void
    {
        $admin = $this->admin();

        // No branches set up: there is nothing to pick, so none is required.
        $this->actingAs($admin)
            ->post(route('players.store'), ['firstname' => 'Ali'])
            ->assertSessionHasErrors(['lastname'])
            ->assertSessionDoesntHaveErrors(['branch_ids']);

        $branch = Branch::create(['name' => 'Football']);

        $this->post(route('players.store'), ['firstname' => 'Ali', 'lastname' => 'Benali'])
            ->assertSessionHasErrors(['branch_ids']);

        $this->post(route('players.store'), ['firstname' => 'Ali', 'lastname' => 'Benali', 'branch_ids' => [$branch->id]])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_list_filters_by_status(): void
    {
        $status = PlayerStatus::query()->firstOrFail();
        $this->player(['firstname' => 'Ali', 'status_id' => $status->id]);
        $this->player(['firstname' => 'Sami', 'status_id' => null]);

        $this->actingAs($this->admin())
            ->get(route('players.index', ['status' => $status->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('players.data', 1)
                ->where('players.data.0.firstname', 'Ali'));
    }

    #[Test]
    public function the_page_size_follows_per_page_within_the_allowed_sizes(): void
    {
        foreach (range(1, 60) as $i) {
            $this->player(['firstname' => "P{$i}"]);
        }

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('players.index', ['per_page' => 50]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 50));

        $this->actingAs($admin)->get(route('players.index', ['per_page' => 100]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 60));

        // Anything outside the allowed sizes falls back to 25.
        $this->actingAs($admin)->get(route('players.index', ['per_page' => 5000]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 25));
    }
}
