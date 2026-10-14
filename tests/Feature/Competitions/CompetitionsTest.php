<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_competition_is_created_for_a_team_season_with_an_optional_format_of_its_sport(): void
    {
        $admin = $this->admin();
        $team = $this->team();
        $payload = ['team_id' => $team->id, 'season_start_year' => 2026, 'name' => 'Regional league', 'type' => 'league'];

        $this->actingAs($admin)->post(route('competitions.store'), $payload + ['game_format_id' => $this->format($this->sportBranch('Handball'))->id])
            ->assertSessionHasErrors('game_format_id');

        $response = $this->actingAs($admin)->post(route('competitions.store'), $payload);
        $competition = Competition::sole();
        $response->assertRedirect(route('competitions.show', $competition))->assertSessionHas('success', 'flash.competition_created');
        $this->assertNull($competition->game_format_id);
    }

    #[Test]
    public function a_coach_creates_competitions_only_for_teams_coached_that_season(): void
    {
        $team = $this->team();
        $coach = $this->coach();
        $payload = ['team_id' => $team->id, 'season_start_year' => 2026, 'name' => 'Cup', 'type' => 'cup'];

        $this->actingAs($coach)->post(route('competitions.store'), $payload)->assertForbidden();
        $this->assignCoach($team, $coach, 2026);
        $this->actingAs($coach)->post(route('competitions.store'), $payload)->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_squad_takes_roster_players_only_and_hides_removed_players_who_already_played(): void
    {
        $admin = $this->admin();
        $team = $this->team();
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $competition = $this->competition($team, 2026);

        $this->actingAs($admin)->put(route('competitions.squad.update', $competition), ['player_ids' => [$a->id, $outsider->id]])
            ->assertSessionHasErrors('player_ids.1');
        $this->actingAs($admin)->put(route('competitions.squad.update', $competition), ['player_ids' => [$a->id, $b->id, $c->id]])
            ->assertSessionHas('success', 'flash.squad_saved');

        $played = $this->game($team, ['competition_id' => $competition->id, 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        GamePlayer::create(['game_id' => $played->id, 'player_id' => $b->id, 'called_up' => true]);

        $this->actingAs($admin)->put(route('competitions.squad.update', $competition), ['player_ids' => [$a->id]])->assertSessionHasNoErrors();

        $rows = $competition->players()->get()->mapWithKeys(fn ($p) => [$p->id => (bool) $p->pivot->hidden])->all();
        $this->assertEquals([$a->id => false, $b->id => true], $rows); // order-insensitive
    }

    #[Test]
    public function the_page_shows_the_squad_and_the_record(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $competition = $this->competition($team, 2026);
        $competition->players()->attach($a->id);
        $this->game($team, ['competition_id' => $competition->id, 'status' => GameStatus::Played, 'our_score' => 3, 'their_score' => 1]);

        $this->actingAs($this->admin())->get(route('competitions.show', $competition))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Competitions/Show')
                ->where('roster.0.in_squad', true)
                ->where('record.wins', 1)->where('record.goals_for', 3)
                ->has('games', 1));
    }

    #[Test]
    public function a_competition_with_matches_is_archived_instead_of_deleted(): void
    {
        $admin = $this->admin();
        $team = $this->team();
        $used = $this->competition($team, 2026);
        $this->game($team, ['competition_id' => $used->id]);
        $unused = $this->competition($team, 2026, ['name' => 'Cup']);

        $this->actingAs($admin)->delete(route('competitions.destroy', $used))->assertSessionHas('success', 'flash.competition_archived');
        $this->assertTrue($used->fresh()->archived);
        $this->actingAs($admin)->delete(route('competitions.destroy', $unused))->assertSessionHas('success', 'flash.competition_deleted');
        $this->assertModelMissing($unused);
    }
}
