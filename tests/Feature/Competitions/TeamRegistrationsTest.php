<?php

namespace Tests\Feature\Competitions;

use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class TeamRegistrationsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private Team $footTeam;

    private Team $swimTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footTeam = $this->team($this->senior, ['branch_id' => $this->foot->id]);
        $this->swimTeam = $this->team($this->u15, ['branch_id' => $this->swim->id]);
    }

    private function teamPage(Team $team)
    {
        return $this->actingAs($this->admin())->get(route('teams.show', ['team' => $team, 'season' => 2026]))->assertOk();
    }

    private function row(array $rows, string $key, int $id): ?array
    {
        return collect($rows)->firstWhere($key, $id);
    }

    #[Test]
    public function the_roster_shows_the_category_of_the_teams_branch(): void
    {
        $player = $this->bothActive();
        $this->onRoster($this->swimTeam, 2026, [$player]);

        $this->teamPage($this->swimTeam)->assertInertia(fn (Assert $page) => $page
            ->where('roster.0.player_id', $player->id)
            ->where('roster.0.category', 'U15')
            ->where('roster.0.left_branch', false));
    }

    #[Test]
    public function a_player_who_left_the_branch_stays_on_the_roster_flagged(): void
    {
        $left = $this->leftFootball();
        $stays = $this->bothActive();
        $this->onRoster($this->footTeam, 2026, [$left, $stays]);

        $this->teamPage($this->footTeam)->assertInertia(fn (Assert $page) => $page
            ->has('roster', 2)
            ->where('roster', fn ($rows) => $this->row($rows->all(), 'player_id', $left->id)['left_branch'] === true
                && $this->row($rows->all(), 'player_id', $left->id)['category'] === 'Senior'
                && $this->row($rows->all(), 'player_id', $stays->id)['left_branch'] === false));

        $this->assertDatabaseHas('team_players', ['team_id' => $this->footTeam->id, 'player_id' => $left->id, 'season_start_year' => 2026]);
    }

    #[Test]
    public function left_both_and_no_registration_are_flagged_by_their_own_status(): void
    {
        $gone = $this->leftBoth();
        $flat = $this->noRegistration();
        $this->onRoster($this->footTeam, 2026, [$gone, $flat]);

        $this->teamPage($this->footTeam)->assertInertia(fn (Assert $page) => $page
            ->where('roster', fn ($rows) => $this->row($rows->all(), 'player_id', $gone->id)['left_branch'] === true
                && $this->row($rows->all(), 'player_id', $flat->id)['left_branch'] === false
                && $this->row($rows->all(), 'player_id', $flat->id)['category'] === 'Senior'));
    }

    #[Test]
    public function in_sport_means_an_active_registration_in_the_teams_branch(): void
    {
        $both = $this->bothActive();
        $leftFoot = $this->leftFootball();
        $gone = $this->leftBoth();
        $flat = $this->noRegistration();

        $this->teamPage($this->footTeam)->assertInertia(fn (Assert $page) => $page
            ->where('candidates', function ($rows) use ($both, $leftFoot, $gone, $flat) {
                $rows = $rows->all();

                return $this->row($rows, 'id', $both->id)['in_sport'] === true
                    && $this->row($rows, 'id', $leftFoot->id)['in_sport'] === false
                    && $this->row($rows, 'id', $flat->id)['in_sport'] === false
                    && $this->row($rows, 'id', $gone->id) === null;
            }));
    }

    #[Test]
    public function the_candidates_category_is_the_one_in_the_teams_branch(): void
    {
        $both = $this->bothActive();
        $flat = $this->noRegistration();

        $this->teamPage($this->swimTeam)->assertInertia(fn (Assert $page) => $page
            ->where('candidates', fn ($rows) => $this->row($rows->all(), 'id', $both->id)['category_id'] === $this->u15->id
                && $this->row($rows->all(), 'id', $flat->id)['category_id'] === $this->senior->id));
    }

    #[Test]
    public function a_saved_roster_keeps_a_left_player_when_they_are_sent_back(): void
    {
        $left = $this->leftFootball();
        $this->onRoster($this->footTeam, 2026, [$left]);

        $this->actingAs($this->admin())->put(route('teams.roster.update', $this->footTeam), [
            'season' => 2026, 'players' => [['player_id' => $left->id, 'shirt_number' => 7]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, (int) DB::table('team_players')->where('player_id', $left->id)->value('shirt_number'));
    }

    #[Test]
    public function the_competition_page_flags_and_labels_by_the_teams_branch(): void
    {
        $left = $this->leftFootball();
        $swimmer = $this->bothActive();
        $this->onRoster($this->footTeam, 2026, [$left]);
        $this->onRoster($this->swimTeam, 2026, [$swimmer]);
        $foot = $this->competition($this->footTeam, 2026);
        $swim = $this->competition($this->swimTeam, 2026);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('competitions.show', $foot))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('roster.0.left_branch', true));
        $this->actingAs($admin)->get(route('competitions.show', $swim))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roster.0.category', 'U15')
                ->where('roster.0.left_branch', false));
    }

    private function callUp($game, int $playerId)
    {
        return $this->actingAs($this->admin())->put(route('games.call-up.update', $game), [
            'players' => [['player_id' => $playerId, 'called_up' => true, 'not_called_reason' => null]],
        ]);
    }

    #[Test]
    public function a_player_who_left_the_branch_is_not_a_call_up_candidate(): void
    {
        $left = $this->leftFootball();
        $stays = $this->bothActive();
        $gone = $this->leftBoth();
        $flatLeft = $this->noRegistration('FlatLeft');
        $flatLeft->update(['status_id' => \App\Models\PlayerStatus::where('code', 'left')->value('id')]);
        $flat = $this->noRegistration('FlatStays');
        $this->onRoster($this->footTeam, 2026, [$left, $stays, $gone, $flatLeft, $flat]);
        $game = $this->game($this->footTeam);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all()
                    === collect([$stays->id, $flat->id])->sort()->values()->all()));
    }

    #[Test]
    public function they_stay_a_candidate_on_the_other_sports_team(): void
    {
        $player = $this->leftFootball();
        $this->onRoster($this->swimTeam, 2026, [$player]);
        $game = $this->game($this->swimTeam);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.player_id', $player->id)
                ->where('candidates.0.category', 'U15'));
    }

    #[Test]
    public function the_server_refuses_a_call_up_that_names_a_player_who_left(): void
    {
        $left = $this->leftFootball();
        $this->onRoster($this->footTeam, 2026, [$left]);
        $game = $this->game($this->footTeam);

        $this->callUp($game, $left->id)->assertSessionHasErrors('players.0.player_id');

        $this->assertDatabaseMissing('game_players', ['game_id' => $game->id, 'player_id' => $left->id]);
    }

    #[Test]
    public function a_call_up_made_before_they_left_stays_with_its_events(): void
    {
        $player = $this->bothActive();
        $other = $this->bothActive('Other');
        $this->onRoster($this->footTeam, 2026, [$player, $other]);
        $game = $this->game($this->footTeam);
        $this->callUp($game, $player->id)->assertSessionHasNoErrors();
        $event = GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'player_id' => $player->id, 'period' => 1, 'minute' => 10]);

        app(\App\Services\Player\RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        // Saving the call-up again, now without them, must not touch their row.
        $this->callUp($game, $other->id)->assertSessionHasNoErrors();

        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('player_id', $player->id)->sole()->called_up);
        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'player_id' => $player->id]);
    }

    #[Test]
    public function the_season_copy_skips_who_left_the_teams_branch_only(): void
    {
        $leftFoot = $this->leftFootball();
        $stays = $this->bothActive();
        $gone = $this->leftBoth();
        $flat = $this->noRegistration();
        $flatLeft = $this->noRegistration('FlatLeft');
        $flatLeft->update(['status_id' => \App\Models\PlayerStatus::where('code', 'left')->value('id')]);
        $this->onRoster($this->footTeam, 2025, [$leftFoot, $stays, $gone, $flat, $flatLeft]);
        $this->onRoster($this->swimTeam, 2025, [$leftFoot]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('teams.roster.copy', $this->footTeam), ['season' => 2026])
            ->assertSessionHas('success', 'flash.team_roster_copied');
        $this->assertEqualsCanonicalizing([$stays->id, $flat->id], $this->footTeam->rosterFor(2026)->pluck('players.id')->all());

        $this->actingAs($admin)->post(route('teams.roster.copy', $this->swimTeam), ['season' => 2026])
            ->assertSessionHas('success', 'flash.team_roster_copied');
        $this->assertSame([$leftFoot->id], $this->swimTeam->rosterFor(2026)->pluck('players.id')->all());

        $this->assertDatabaseHas('team_players', ['team_id' => $this->footTeam->id, 'player_id' => $leftFoot->id, 'season_start_year' => 2025]);
    }

    #[Test]
    public function a_player_from_another_sport_is_still_copied(): void
    {
        $footballer = $this->bothActive();
        $solo = \App\Models\Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020]);
        app(\App\Services\Player\RegistrationService::class)->register($solo, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $this->onRoster($this->swimTeam, 2025, [$footballer, $solo]);

        $this->actingAs($this->admin())->post(route('teams.roster.copy', $this->swimTeam), ['season' => 2026]);

        $this->assertEqualsCanonicalizing([$footballer->id, $solo->id], $this->swimTeam->rosterFor(2026)->pluck('players.id')->all());
    }

    #[Test]
    public function the_convocation_sheet_shows_the_category_of_the_teams_branch(): void
    {
        // The team's own label says Senior, so "U15" can only come from the player's row.
        $team = $this->team($this->senior, ['branch_id' => $this->swim->id]);
        $player = $this->bothActive();
        $this->onRoster($team, 2026, [$player]);
        $game = $this->game($team);
        $this->callUp($game, $player->id)->assertSessionHasNoErrors();

        $html = null;
        $this->mock(PdfService::class, function (MockInterface $mock) use (&$html) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $rendered) use (&$html) {
                $html = $rendered;

                return new Response('pdf');
            });
        });

        $this->actingAs($this->admin())->get(route('games.convocation', $game))->assertOk();

        $this->assertStringContainsString('U15', $html);
    }
}
