<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerMergeRightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function players_has_the_extra_merge_action(): void
    {
        $this->assertSame(['view', 'add', 'edit', 'delete', 'merge'], Role::actionsFor('players'));
        $this->assertContains('merge', Role::allPermissions()['players']);
    }

    #[Test]
    public function the_grant_migration_adds_merge_to_system_roles_only_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view', 'edit']]]);
        $bare = Role::factory()->create(['is_system' => true, 'permissions' => ['documents' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);
        $migration = require database_path('migrations/2026_11_26_300000_grant_player_merge_permission.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'edit', 'merge'], $system->fresh()->permissions['players']);
        $this->assertSame(['view', 'add', 'edit', 'delete', 'merge'], $bare->fresh()->permissions['players']);
        $this->assertSame(['view'], $other->fresh()->permissions['players']);

        $migration->down();
        $this->assertSame(['view', 'edit'], $system->fresh()->permissions['players']);
    }
}
