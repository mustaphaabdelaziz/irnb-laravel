<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function mail_is_the_secretariat_module_with_the_canonical_actions_only(): void
    {
        $this->assertContains('mail', Role::MODULES);
        $this->assertSame(['mail'], Role::MODULE_GROUPS['secretariat']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], Role::allPermissions()['mail']);
        $this->assertArrayNotHasKey('mail', Role::EXTRA_ACTIONS);
    }

    #[Test]
    public function routes_map_to_the_rights_the_spec_names(): void
    {
        foreach (['index', 'show', 'print', 'scan'] as $route) {
            $this->assertSame(['mail', 'view'], PermissionMap::resolve('mail.'.$route), $route);
        }
        foreach (['create', 'store', 'sources.show', 'sources.store'] as $route) {
            $this->assertSame(['mail', 'add'], PermissionMap::resolve('mail.'.$route), $route);
        }
        foreach (['edit', 'update'] as $route) {
            $this->assertSame(['mail', 'edit'], PermissionMap::resolve('mail.'.$route), $route);
        }
        // Entries are never deleted: the delete right is cancelling.
        $this->assertSame(['mail', 'delete'], PermissionMap::resolve('mail.cancel'));
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_module_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view']]]);
        $trimmed = Role::factory()->create(['is_system' => true, 'permissions' => ['mail' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);
        $migration = require database_path('migrations/2026_11_26_100010_grant_mail_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['mail']);
        $this->assertSame(['view'], $system->fresh()->permissions['players']);
        // A system role someone already trimmed keeps its choice.
        $this->assertSame(['view'], $trimmed->fresh()->permissions['mail']);
        $this->assertArrayNotHasKey('mail', $other->fresh()->permissions);
    }
}
