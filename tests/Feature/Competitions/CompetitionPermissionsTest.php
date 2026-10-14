<?php

namespace Tests\Feature\Competitions;

use App\Models\Role;
use App\Models\User;
use App\Services\Competitions\TeamAccess;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionPermissionsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function the_new_modules_exist_and_routes_map_to_them(): void
    {
        foreach (['teams', 'competitions', 'games', 'game_formats', 'opponents', 'venues'] as $module) {
            $this->assertContains($module, Role::MODULES);
        }
        $this->assertSame(['teams', 'view'], PermissionMap::resolve('teams.index'));
        $this->assertSame(['teams', 'edit'], PermissionMap::resolve('teams.roster.update'));
        $this->assertSame(['competitions', 'edit'], PermissionMap::resolve('competitions.squad.update'));
        $this->assertSame(['games', 'add'], PermissionMap::resolve('games.store'));
        $this->assertSame(['games', 'edit'], PermissionMap::resolve('games.call-up.update'));
        // "convocation" is not a view verb: printing the sheet must not need edit rights.
        $this->assertSame(['games', 'view'], PermissionMap::resolve('games.convocation'));
        $this->assertSame(['game_formats', 'view'], PermissionMap::resolve('game-formats.index'));
        $this->assertSame(['opponents', 'add'], PermissionMap::resolve('opponents.store'));
        $this->assertSame(['venues', 'delete'], PermissionMap::resolve('venues.destroy'));
    }

    #[Test]
    public function the_migration_grants_system_roles_everything_and_the_coach_role_its_scoped_rights(): void
    {
        $admin = Role::factory()->create(['key' => 'administrator', 'is_system' => true, 'permissions' => ['players' => ['view']]]);
        $coach = Role::factory()->create(['key' => 'coach', 'permissions' => ['players' => ['view']]]);
        $cashier = Role::factory()->create(['key' => 'cashier', 'permissions' => ['transactions' => ['view']]]);

        (require database_path('migrations/2026_10_15_100003_grant_competition_permissions.php'))->up();

        $this->assertSame(Role::ACTIONS, $admin->fresh()->permissions['games']);
        $this->assertSame(Role::ACTIONS, $admin->fresh()->permissions['venues']);
        $this->assertFalse($admin->fresh()->own_teams_only);
        $this->assertSame(['view', 'add', 'edit'], $coach->fresh()->permissions['games']);
        $this->assertSame(['view', 'edit'], $coach->fresh()->permissions['teams']);
        $this->assertTrue($coach->fresh()->own_teams_only);
        $this->assertArrayNotHasKey('games', $cashier->fresh()->permissions);
    }

    #[Test]
    public function unscoped_users_manage_every_team(): void
    {
        $team = $this->team();
        $director = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['teams' => ['view', 'edit']]])->id]);

        $this->assertTrue(TeamAccess::canManage($this->admin(), $team, 2026));
        $this->assertTrue(TeamAccess::canManage($director, $team, 2026));
        $this->assertNull(TeamAccess::manageableTeamIds($director, 2026));
    }

    #[Test]
    public function a_scoped_coach_manages_only_the_teams_coached_that_season(): void
    {
        $team = $this->team();
        $other = $this->team();
        $coach = $this->coach();
        $this->assignCoach($team, $coach, 2026);

        $this->assertTrue(TeamAccess::isScoped($coach));
        $this->assertTrue(TeamAccess::canManage($coach, $team, 2026));
        $this->assertFalse(TeamAccess::canManage($coach, $team, 2025));
        $this->assertFalse(TeamAccess::canManage($coach, $other, 2026));
        $this->assertSame([$team->id], TeamAccess::manageableTeamIds($coach, 2026));
    }

    #[Test]
    public function the_role_editor_saves_the_own_teams_flag(): void
    {
        $superadmin = User::factory()->create(['privileges' => ['superadmin']]);

        $this->actingAs($superadmin)->post(route('roles.store'), [
            'name' => ['en' => 'Assistant coach'],
            'permissions' => ['games' => ['view']],
            'own_teams_only' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Role::latest('id')->first()->own_teams_only);
    }

    #[Test]
    public function system_roles_can_never_be_limited_to_own_teams(): void
    {
        $superadmin = User::factory()->create(['privileges' => ['superadmin']]);
        $system = Role::factory()->create(['key' => 'admin_sys', 'is_system' => true]);
        $root = Role::where('key', 'superadmin')->first() ?? Role::factory()->create(['key' => 'superadmin', 'is_system' => true]);
        $payload = ['name' => ['en' => 'Locked'], 'permissions' => ['games' => ['view']], 'own_teams_only' => true];

        $this->actingAs($superadmin)->put(route('roles.update', $system), $payload)->assertSessionHasNoErrors();
        $this->actingAs($superadmin)->put(route('roles.update', $root), $payload)->assertSessionHasNoErrors();

        $this->assertFalse($system->fresh()->own_teams_only);
        $this->assertFalse($root->fresh()->own_teams_only);
    }
}
