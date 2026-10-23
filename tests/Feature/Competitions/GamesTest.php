<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Game;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class GamesTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + ['opponent_id' => $this->opponent()->id, 'side' => 'home', 'date' => '2027-02-06', 'kickoff_time' => '15:00'];
    }

    #[Test]
    public function a_friendly_takes_its_season_from_the_date_and_the_team_format(): void
    {
        $team = $this->team();
        $response = $this->actingAs($this->admin())->post(route('games.store'), $this->payload(['team_id' => $team->id]));

        $game = Game::sole();
        $response->assertRedirect(route('games.show', $game))->assertSessionHas('success', 'flash.game_created');
        $this->assertSame(2026, $game->season_start_year);
        $this->assertSame($team->default_game_format_id, $game->game_format_id);
        $this->assertSame(GameStatus::Scheduled, $game->status);
    }

    #[Test]
    public function a_competition_match_takes_the_competition_season_and_format_override(): void
    {
        $team = $this->team();
        $override = $this->format($team->branch, ['name' => 'Cup format']);
        $competition = $this->competition($team, 2026, ['game_format_id' => $override->id]);
        $otherTeams = $this->competition($this->team(), 2026);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('games.store'), $this->payload(['team_id' => $team->id, 'competition_id' => $otherTeams->id]))
            ->assertSessionHasErrors('competition_id');
        $this->actingAs($admin)->post(route('games.store'), $this->payload(['team_id' => $team->id, 'competition_id' => $competition->id, 'date' => '2027-08-30']))
            ->assertSessionHasNoErrors();

        $game = Game::sole();
        $this->assertSame(2026, $game->season_start_year);
        $this->assertSame($override->id, $game->game_format_id);
    }

    #[Test]
    public function creating_a_match_can_cancel_the_teams_training_that_day(): void
    {
        $team = $this->team();
        $session = TrainingSession::create([
            'branch_id' => $team->branch_id, 'category_id' => $team->category_id, 'date' => '2027-02-06', 'start_time' => '10:00', 'end_time' => '11:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->post(route('games.store'), $this->payload(['team_id' => $team->id, 'cancel_training' => true]))
            ->assertSessionHas('success', 'flash.game_created_training_cancelled');

        $this->assertSame(SessionState::Cancelled, $session->fresh()->state);
        $this->assertStringContainsString('Rivals FC', $session->fresh()->cancel_reason);
    }

    #[Test]
    public function a_football_match_leaves_the_same_categorys_swimming_training_alone(): void
    {
        $team = $this->team();
        $swimming = $this->sportBranch('Swimming');
        $slot = ['category_id' => $team->category_id, 'date' => '2027-02-06', 'start_time' => '10:00', 'end_time' => '11:30', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned];
        $football = TrainingSession::create(['branch_id' => $team->branch_id] + $slot);
        $swim = TrainingSession::create(['branch_id' => $swimming->id] + $slot);

        $this->actingAs($this->admin())->post(route('games.store'), $this->payload(['team_id' => $team->id, 'cancel_training' => true]))
            ->assertSessionHas('success', 'flash.game_created_training_cancelled');

        $this->assertSame(SessionState::Cancelled, $football->fresh()->state);
        $this->assertSame(SessionState::Planned, $swim->fresh()->state);
    }

    #[Test]
    public function a_coach_schedules_matches_only_for_teams_coached(): void
    {
        $team = $this->team();
        $coach = $this->coach();
        $this->actingAs($coach)->post(route('games.store'), $this->payload(['team_id' => $team->id]))->assertForbidden();

        $this->assignCoach($team, $coach, 2026);
        $this->actingAs($coach)->post(route('games.store'), $this->payload(['team_id' => $team->id]))->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_coach_cannot_move_a_friendly_into_a_season_they_do_not_coach(): void
    {
        $team = $this->team();
        $game = $this->game($team);
        $coach = $this->coach();
        $this->assignCoach($team, $coach, 2026);

        $this->actingAs($coach)->put(route('games.update', $game), $this->payload(['date' => '2027-10-01', 'status' => 'scheduled']))
            ->assertForbidden();
        $this->assertSame('2026-10-17', $game->fresh()->date);
        $this->assertSame(2026, $game->fresh()->season_start_year);

        $this->actingAs($coach)->put(route('games.update', $game), $this->payload(['date' => '2027-02-06', 'status' => 'scheduled']))
            ->assertSessionHas('success', 'flash.game_updated');
    }

    #[Test]
    public function a_coach_not_assigned_cannot_edit_or_delete_a_match(): void
    {
        $team = $this->team();
        $game = $this->game($team);
        $coach = $this->coach(['games' => ['view', 'add', 'edit', 'delete']]);

        $this->actingAs($coach)->put(route('games.update', $game), $this->payload(['date' => '2026-10-18', 'status' => 'scheduled']))
            ->assertForbidden();
        $this->actingAs($coach)->delete(route('games.destroy', $game))->assertForbidden();
        $this->assertSame('2026-10-17', $game->fresh()->date);
    }

    #[Test]
    public function a_played_match_needs_a_score_and_other_statuses_clear_it(): void
    {
        $team = $this->team();
        $game = $this->game($team);
        $admin = $this->admin();
        $update = fn (array $extra) => $this->actingAs($admin)->put(route('games.update', $game), $this->payload(['date' => '2026-10-17'] + $extra));

        $update(['status' => 'played'])->assertSessionHasErrors(['our_score', 'their_score']);
        $update(['status' => 'played', 'our_score' => 2, 'their_score' => 1])->assertSessionHas('success', 'flash.game_updated');
        $this->assertSame(2, $game->fresh()->our_score);

        $update(['status' => 'postponed', 'our_score' => 2, 'their_score' => 1])->assertSessionHasNoErrors();
        $this->assertNull($game->fresh()->our_score);
    }

    #[Test]
    public function the_list_splits_upcoming_and_past_and_the_month_view_shows_the_teams_training(): void
    {
        $team = $this->team();
        $this->game($team, ['date' => now()->addDays(3)->toDateString()]);
        $this->game($team, ['date' => now()->subDays(3)->toDateString(), 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        $slot = ['category_id' => $team->category_id, 'date' => now()->addDay()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned];
        TrainingSession::create(['branch_id' => $team->branch_id] + $slot);
        TrainingSession::create(['branch_id' => $this->sportBranch('Swimming')->id] + $slot); // another sport: not the team's training
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('games.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Index')->has('upcoming', 1)->has('past.data', 1));

        $this->actingAs($admin)->get(route('games.index', ['view' => 'month', 'month' => now()->addDay()->format('Y-m'), 'team_id' => $team->id]))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'month')->has('sessions', 1));
    }

    #[Test]
    public function the_match_page_renders_and_a_match_can_be_deleted(): void
    {
        $game = $this->game($this->team());
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Show')->where('game.opponent.name', 'Rivals FC')->where('canManage', true));

        $this->actingAs($admin)->delete(route('games.destroy', $game))->assertRedirect(route('games.index'));
        $this->assertModelMissing($game);
    }
}
