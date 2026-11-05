<?php

namespace Tests\Feature\Competitions;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
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
}
