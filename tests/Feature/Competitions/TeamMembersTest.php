<?php

namespace Tests\Feature\Competitions;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\Team;
use App\Services\Competitions\TeamMembers;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class TeamMembersTest extends TestCase
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

    private function loaded(Player $player): Player
    {
        return Player::with(TeamMembers::RELATIONS)->findOrFail($player->id);
    }

    private function footballOnly(string $name = 'Solo'): Player
    {
        $player = Player::create(['firstname' => $name, 'lastname' => 'Test', 'join_year' => 2020, 'membership_id' => $this->fixtureId()]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        return $player->refresh();
    }

    #[Test]
    public function both_active_reads_each_teams_own_registration(): void
    {
        $player = $this->loaded($this->bothActive());

        $this->assertSame('Senior', TeamMembers::of($this->footTeam)->category($player)->name);
        $this->assertSame('U15', TeamMembers::of($this->swimTeam)->category($player)->name);
        $this->assertSame($this->u15->id, TeamMembers::of($this->swimTeam)->categoryId($player));
        $this->assertFalse(TeamMembers::of($this->footTeam)->hasLeft($player));
        $this->assertTrue(TeamMembers::of($this->swimTeam)->isActiveMember($player));
    }

    #[Test]
    public function left_football_counts_on_the_football_team_only(): void
    {
        $player = $this->loaded($this->leftFootball());
        $foot = TeamMembers::of($this->footTeam);
        $swim = TeamMembers::of($this->swimTeam);

        $this->assertTrue($foot->hasLeft($player));
        $this->assertFalse($foot->isActiveMember($player));
        $this->assertSame('Senior', $foot->category($player)->name);
        $this->assertFalse($swim->hasLeft($player));
        $this->assertTrue($swim->isActiveMember($player));
    }

    #[Test]
    public function left_both_counts_on_both_teams(): void
    {
        $player = $this->loaded($this->leftBoth());

        $this->assertTrue(TeamMembers::of($this->footTeam)->hasLeft($player));
        $this->assertTrue(TeamMembers::of($this->swimTeam)->hasLeft($player));
    }

    #[Test]
    public function no_registration_uses_the_players_own_columns(): void
    {
        $position = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $flat = $this->noRegistration();
        $flat->update(['licence_number' => 'OWN-1', 'position_id' => $position->id]);
        $members = TeamMembers::of($this->footTeam);
        $player = $this->loaded($flat);

        $this->assertFalse($members->hasLeft($player));
        $this->assertFalse($members->isActiveMember($player));
        $this->assertSame('Senior', $members->category($player)->name);
        $this->assertSame($this->senior->id, $members->categoryId($player));
        $this->assertSame('OWN-1', $members->licence($player));
        $this->assertSame('GK', $members->position($player)->abbreviation);

        $flat->update(['status_id' => PlayerStatus::where('code', 'left')->value('id')]);
        $this->assertTrue($members->hasLeft($this->loaded($flat)));
    }

    #[Test]
    public function a_member_of_another_sport_shows_their_category_but_no_licence_or_position(): void
    {
        $position = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $player = $this->footballOnly();
        $player->registrations()->update(['licence_number' => 'FB-1', 'position_id' => $position->id]);
        $members = TeamMembers::of($this->swimTeam);
        $loaded = $this->loaded($player);

        $this->assertFalse($members->hasLeft($loaded));
        $this->assertFalse($members->isActiveMember($loaded));
        $this->assertSame('Senior', $members->category($loaded)->name);
        $this->assertNull($members->licence($loaded));
        $this->assertNull($members->position($loaded));
    }

    #[Test]
    public function a_member_of_another_sport_who_left_it_has_left_this_team_too(): void
    {
        $player = $this->footballOnly();
        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->assertTrue(TeamMembers::of($this->swimTeam)->hasLeft($this->loaded($player)));
    }

    #[Test]
    public function licence_and_position_come_from_the_teams_branch_not_the_mirror(): void
    {
        $keeper = Position::create(['branch_id' => $this->foot->id, 'name' => 'Keeper', 'abbreviation' => 'GK']);
        $free = Position::create(['branch_id' => $this->swim->id, 'name' => 'Freestyle', 'abbreviation' => 'FR']);
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => 'FB-1', 'position_id' => $keeper->id]);
        $player->registrations()->where('branch_id', $this->swim->id)->update(['licence_number' => 'SW-1', 'position_id' => $free->id]);
        $loaded = $this->loaded($player);

        $this->assertSame('FB-1', TeamMembers::of($this->footTeam)->licence($loaded));
        $this->assertSame('SW-1', TeamMembers::of($this->swimTeam)->licence($loaded));
        $this->assertSame('FR', TeamMembers::of($this->swimTeam)->position($loaded)->abbreviation);
    }

    #[Test]
    public function a_list_costs_no_query_per_player(): void
    {
        foreach (range(1, 5) as $n) {
            $this->bothActive("P$n");
        }
        $players = Player::with(TeamMembers::RELATIONS)->get();
        $members = TeamMembers::of($this->swimTeam);

        DB::enableQueryLog();
        $players->each(function (Player $player) use ($members) {
            $members->hasLeft($player);
            $members->isActiveMember($player);
            $members->category($player);
            $members->categoryId($player);
            $members->position($player);
            $members->licence($player);
        });
        $this->assertCount(0, DB::getQueryLog());
    }
}
