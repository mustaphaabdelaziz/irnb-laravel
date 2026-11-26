<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Secretariat\MissionTravelers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MissionTravelersTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        app()->setLocale('fr');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function travelers(): MissionTravelers
    {
        return app(MissionTravelers::class);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    #[Test]
    public function each_picker_carries_the_persons_default_function(): void
    {
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        User::factory()->create(['firstname' => 'Ancien', 'lastname' => 'Compte', 'is_active' => false]);
        BoardRole::create(['name' => 'media_test', 'label' => 'Chargé de communication test', 'level' => 'bureau']);
        $president = BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Président Test', 'role' => 'president', 'status' => 'active']);
        $media = BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Média Test', 'role' => 'media_test', 'status' => 'active']);
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => 'Ancien Membre', 'role' => 'treasurer', 'status' => 'former']);
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $archived = $this->player($u15, ['archived' => true]);
        $gone = $this->player($u15, ['status_id' => Player::leftStatusId()]);

        $options = $this->travelers()->options(User::factory()->admin()->create());

        $users = collect($options['users']);
        $this->assertSame(['value' => $coach->id, 'label' => 'Karim Coach', 'function' => 'Entraîneur'], $users->firstWhere('value', $coach->id));
        $this->assertNull($users->firstWhere('label', 'Ancien Compte'));

        $members = collect($options['boardMembers']);
        // The built-in role name is translated; a role with a typed label shows that label.
        $this->assertSame('Président', $members->firstWhere('value', $president->id)['function']);
        $this->assertSame('Chargé de communication test', $members->firstWhere('value', $media->id)['function']);
        $this->assertSame('Chargé de communication test', $members->firstWhere('value', $media->id)['description']);
        $this->assertNull($members->firstWhere('label', 'Ancien Membre'));

        $players = collect($options['players']);
        $row = $players->firstWhere('value', $player->id);
        $this->assertSame($player->fullname, $row['label']);
        $this->assertSame('Joueur — U15', $row['function']);
        $this->assertStringContainsString($player->membership_id, $row['description']);
        $this->assertNull($players->firstWhere('value', $archived->id));
        $this->assertNull($players->firstWhere('value', $gone->id));
    }

    #[Test]
    public function players_and_teams_are_offered_only_to_who_may_view_them(): void
    {
        // A category name no seeded row repeats, so the label carries no sport suffix.
        $this->team($this->category('Cadets Test'), ['name' => 'Cadets A']);

        $plain = $this->travelers()->options($this->userWith(['missions' => ['view', 'add']]));
        $this->assertNull($plain['players']);
        $this->assertNull($plain['teams']);

        $players = $this->travelers()->options($this->userWith(['missions' => ['view', 'add'], 'players' => ['view']]));
        $this->assertIsArray($players['players']);
        $this->assertNull($players['teams']);

        $both = $this->travelers()->options($this->userWith(['missions' => ['view', 'add'], 'players' => ['view'], 'teams' => ['view']]));
        $this->assertSame('Cadets A · Cadets Test', $both['teams'][0]['label']);
    }

    #[Test]
    public function adding_a_team_brings_its_coaches_then_its_players_of_this_season(): void
    {
        $u15 = $this->category('U15');
        $team = $this->team($u15, ['name' => 'Cadets A']);
        $first = $this->player($u15);
        $second = $this->player($u15);
        $gone = $this->player($u15);
        $lastSeason = $this->player($u15);
        $this->onRoster($team, 2026, [$second, $first, $gone]);
        $this->onRoster($team, 2025, [$lastSeason]);
        $gone->update(['status_id' => Player::leftStatusId()]);
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $this->assignCoach($team, $coach, 2026);
        $this->assignCoach($team, User::factory()->create(), 2025);

        $rows = $this->travelers()->team($team);

        $this->assertSame([
            ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Karim Coach', 'function' => 'Entraîneur'],
            ['traveler_type' => 'player', 'traveler_id' => $first->id, 'name' => $first->fullname, 'function' => 'Joueur — U15'],
            ['traveler_type' => 'player', 'traveler_id' => $second->id, 'name' => $second->fullname, 'function' => 'Joueur — U15'],
        ], $rows);
    }

    #[Test]
    public function a_player_without_a_category_is_simply_a_player(): void
    {
        $this->assertSame('Joueur', MissionTravelers::playerFunction(null));
        $this->assertSame('Joueur — U17', MissionTravelers::playerFunction('U17'));
        app()->setLocale('ar');
        $this->assertSame('مدرب', MissionTravelers::coachFunction());
        $this->assertSame('لاعب — U17', MissionTravelers::playerFunction('U17'));
    }

    #[Test]
    public function adding_a_team_leaves_out_archived_players_and_inactive_coaches(): void
    {
        $u15 = $this->category('U15');
        $team = $this->team($u15, ['name' => 'Cadets A']);
        $kept = $this->player($u15);
        $archived = $this->player($u15);
        $this->onRoster($team, 2026, [$kept, $archived]);
        $archived->update(['archived' => true]);
        $active = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $inactive = User::factory()->create(['firstname' => 'Ancien', 'lastname' => 'Coach', 'is_active' => false]);
        $this->assignCoach($team, $active, 2026);
        $this->assignCoach($team, $inactive, 2026);

        $rows = $this->travelers()->team($team);

        $this->assertSame([$active->id, $kept->id], array_column($rows, 'traveler_id'));
    }
}
