<?php

namespace Tests\Feature\Competitions;

use App\Models\Category;
use App\Models\Team;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function an_admin_creates_a_team_with_a_format_of_its_sport(): void
    {
        $admin = $this->admin();
        $football = $this->sportBranch();
        $handballFormat = $this->format($this->sportBranch('Handball'));
        $category = Category::firstOrCreate(['name' => 'U17']);
        $payload = ['branch_id' => $football->id, 'category_id' => $category->id, 'name' => 'U17 A'];

        $this->actingAs($admin)->post(route('teams.store'), $payload + ['default_game_format_id' => $handballFormat->id])
            ->assertSessionHasErrors('default_game_format_id');

        $response = $this->actingAs($admin)->post(route('teams.store'), $payload + ['default_game_format_id' => $this->format($football)->id]);
        $team = Team::sole();
        $response->assertRedirect(route('teams.show', $team))->assertSessionHas('success', 'flash.team_created');

        $this->actingAs($admin)->post(route('teams.store'), $payload + ['default_game_format_id' => $team->default_game_format_id])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_scoped_coach_cannot_create_teams_even_with_the_add_right(): void
    {
        $coach = $this->coach(['teams' => ['view', 'add', 'edit']]);
        $this->actingAs($coach)->post(route('teams.store'), [])->assertForbidden();
    }

    #[Test]
    public function the_team_page_shows_the_season_roster_and_candidates(): void
    {
        $team = $this->team();
        $season = Season::current()->startYear;
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->player($team->category, ['archived' => true]);
        $this->onRoster($team, $season, [$a]);

        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Teams/Show')
                ->where('season', $season)
                ->has('roster', 1)->where('roster.0.player_id', $a->id)->where('roster.0.shirt_number', 1)
                ->has('candidates', 1)->where('candidates.0.id', $b->id)
                ->where('canManage', true));
    }

    #[Test]
    public function the_roster_is_saved_and_shirt_numbers_can_be_swapped(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $admin = $this->admin();
        $save = fn (array $rows) => $this->actingAs($admin)->put(route('teams.roster.update', $team), ['season' => 2026, 'players' => $rows]);

        $save([['player_id' => $a->id, 'shirt_number' => 10], ['player_id' => $b->id, 'shirt_number' => 7]])
            ->assertSessionHas('success', 'flash.team_roster_saved');
        $save([['player_id' => $a->id, 'shirt_number' => 7], ['player_id' => $b->id, 'shirt_number' => 10]])->assertSessionHasNoErrors();
        $this->assertSame(7, $team->rosterFor(2026)->where('players.id', $a->id)->first()->pivot->shirt_number);

        $save([['player_id' => $a->id, 'shirt_number' => 7], ['player_id' => $b->id, 'shirt_number' => 7]])
            ->assertSessionHasErrors('players.1.shirt_number');

        $save([['player_id' => $b->id, 'shirt_number' => null]])->assertSessionHasNoErrors();
        $this->assertSame([$b->id], $team->rosterFor(2026)->pluck('players.id')->all());
    }

    #[Test]
    public function last_seasons_roster_is_copied_without_archived_players(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2025, [$a, $b]);
        $b->update(['archived' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('teams.roster.copy', $team), ['season' => 2026])
            ->assertSessionHas('success', 'flash.team_roster_copied');
        $this->assertSame([$a->id], $team->rosterFor(2026)->pluck('players.id')->all());
        $this->assertSame(1, $team->rosterFor(2026)->first()->pivot->shirt_number);

        $this->actingAs($admin)->post(route('teams.roster.copy', $team), ['season' => 2026])
            ->assertSessionHas('error', 'flash.team_roster_not_empty');
    }

    #[Test]
    public function only_the_assigned_coach_edits_a_teams_roster_and_coaches(): void
    {
        $team = $this->team();
        $player = $this->player($team->category);
        $coach = $this->coach();
        $rows = ['season' => 2026, 'players' => [['player_id' => $player->id, 'shirt_number' => 9]]];

        $this->actingAs($coach)->put(route('teams.roster.update', $team), $rows)->assertForbidden();
        $this->assignCoach($team, $coach, 2026);
        $this->actingAs($coach)->put(route('teams.roster.update', $team), $rows)->assertSessionHasNoErrors();

        $assistant = $this->coach();
        $this->actingAs($coach)->put(route('teams.coaches.update', $team), [
            'season' => 2026,
            'coaches' => [['user_id' => $coach->id, 'role' => 'head'], ['user_id' => $assistant->id, 'role' => 'assistant']],
        ])->assertSessionHas('success', 'flash.team_coaches_saved');
        $this->assertSame('assistant', $team->coachesFor(2026)->where('users.id', $assistant->id)->first()->pivot->role);
    }

    #[Test]
    public function a_team_with_matches_is_archived_instead_of_deleted(): void
    {
        $admin = $this->admin();
        $used = $this->team();
        $this->game($used);
        $unused = $this->team();

        $this->actingAs($admin)->delete(route('teams.destroy', $used))->assertSessionHas('success', 'flash.team_archived');
        $this->assertTrue($used->fresh()->archived);
        $this->actingAs($admin)->delete(route('teams.destroy', $unused))->assertSessionHas('success', 'flash.team_deleted');
        $this->assertModelMissing($unused);
    }

    #[Test]
    public function a_scoped_coach_cannot_delete_teams_even_with_the_delete_right(): void
    {
        $team = $this->team();
        $coach = $this->coach(['teams' => ['view', 'edit', 'delete']]);
        $this->assignCoach($team, $coach, 2026);

        $this->actingAs($coach)->delete(route('teams.destroy', $team))->assertForbidden();
        $this->assertModelExists($team);
    }

    #[Test]
    public function a_category_or_branch_with_teams_cannot_be_deleted(): void
    {
        $team = $this->team();
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('categories.destroy', $team->category_id))->assertSessionHas('error', 'flash.category_has_teams');
        $this->actingAs($admin)->delete(route('branches.destroy', $team->branch_id))->assertSessionHas('error', 'flash.branch_has_teams');
        $this->assertModelExists($team->category);
    }
}
