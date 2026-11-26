<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function missions_is_a_secretariat_module_with_the_canonical_actions_only(): void
    {
        $this->assertContains('missions', Role::MODULES);
        $this->assertSame(['mail', 'missions'], Role::MODULE_GROUPS['secretariat']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], Role::allPermissions()['missions']);
        $this->assertArrayNotHasKey('missions', Role::EXTRA_ACTIONS);
    }

    #[Test]
    public function routes_map_to_the_rights_the_spec_names(): void
    {
        foreach (['index', 'show', 'print', 'print-traveler', 'team-members'] as $route) {
            $this->assertSame(['missions', 'view'], PermissionMap::resolve('missions.'.$route), $route);
        }
        foreach (['create', 'store'] as $route) {
            $this->assertSame(['missions', 'add'], PermissionMap::resolve('missions.'.$route), $route);
        }
        // Printing takes the order's mail-register reference: an edit right.
        foreach (['edit', 'update', 'register'] as $route) {
            $this->assertSame(['missions', 'edit'], PermissionMap::resolve('missions.'.$route), $route);
        }
        // Orders are never deleted: the delete right is cancelling.
        $this->assertSame(['missions', 'delete'], PermissionMap::resolve('missions.cancel'));
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_module_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['mail' => ['view']]]);
        $trimmed = Role::factory()->create(['is_system' => true, 'permissions' => ['missions' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['mail' => ['view']]]);
        $migration = require database_path('migrations/2026_11_27_100000_grant_mission_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['missions']);
        $this->assertSame(['view'], $system->fresh()->permissions['mail']);
        // A system role someone already trimmed keeps its choice.
        $this->assertSame(['view'], $trimmed->fresh()->permissions['missions']);
        $this->assertArrayNotHasKey('missions', $other->fresh()->permissions);
    }
}
