<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentsPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100001_grant_documents_permission_to_admin_roles.php';

    #[Test]
    public function documents_is_a_permission_module_of_its_own(): void
    {
        $this->assertContains('documents', Role::MODULES);
        $this->assertSame(Role::ACTIONS, Role::allPermissions()['documents']);
    }

    #[Test]
    public function every_document_route_is_gated_by_the_documents_module(): void
    {
        $expected = [
            'players.documents.store' => ['documents', 'add'],
            'players.documents.update' => ['documents', 'edit'],
            'players.documents.exempt' => ['documents', 'edit'],
            'players.documents.unexempt' => ['documents', 'edit'],
            'players.documents.files.store' => ['documents', 'add'],
            'players.documents.files.show' => ['documents', 'view'],
            'players.documents.files.download' => ['documents', 'view'],
            'players.documents.files.destroy' => ['documents', 'delete'],
        ];

        foreach ($expected as $route => $permission) {
            $this->assertSame($permission, PermissionMap::resolve($route), $route);
        }
    }

    #[Test]
    public function the_player_routes_keep_their_own_module(): void
    {
        $this->assertSame(['players', 'view'], PermissionMap::resolve('players.show'));
        $this->assertSame(['players', 'view'], PermissionMap::resolve('players.index'));
        $this->assertSame(['players', 'add'], PermissionMap::resolve('players.transactions.store'));
    }

    #[Test]
    public function document_type_settings_ride_on_the_lookup_module(): void
    {
        $this->assertSame(['categories', 'view'], PermissionMap::resolve('document-types.index'));
        $this->assertSame(['categories', 'add'], PermissionMap::resolve('document-types.store'));
        $this->assertSame(['categories', 'edit'], PermissionMap::resolve('document-types.update'));
        $this->assertSame(['categories', 'delete'], PermissionMap::resolve('document-types.destroy'));
    }

    #[Test]
    public function a_god_admin_has_the_documents_module(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->assertTrue($admin->hasPermission('documents', 'delete'));
        $this->assertSame(Role::ACTIONS, $admin->effectivePermissions()['documents']);
    }

    #[Test]
    public function the_migration_grants_documents_to_the_admin_roles_only(): void
    {
        $administrator = Role::create([
            'key' => 'administrator',
            'name' => ['en' => 'Administrator'],
            'permissions' => ['players' => Role::ACTIONS],
            'is_system' => true,
        ]);
        $superadmin = Role::create([
            'key' => 'superadmin',
            'name' => ['en' => 'Super Admin'],
            'permissions' => ['players' => Role::ACTIONS],
            'is_system' => true,
        ]);
        $coach = Role::create([
            'key' => 'coach',
            'name' => ['en' => 'Coach'],
            'permissions' => ['players' => ['view', 'add', 'edit']],
        ]);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        // Desktop re-runs a migration whose previous run died half-way: it must be harmless.
        $migration->up();

        $this->assertSame(Role::ACTIONS, $administrator->fresh()->permissions['documents']);
        $this->assertSame(Role::ACTIONS, $superadmin->fresh()->permissions['documents']);
        $this->assertSame(Role::ACTIONS, $administrator->fresh()->permissions['players'], 'other modules are untouched');
        $this->assertArrayNotHasKey('documents', $coach->fresh()->permissions, 'non-admin roles are not granted anything');
    }

    #[Test]
    public function player_rights_do_not_include_documents(): void
    {
        $user = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create([
                'key' => 'coach',
                'name' => ['en' => 'Coach'],
                'permissions' => ['players' => Role::ACTIONS],
            ])->id,
        ]);

        $this->assertFalse($user->hasPermission('documents', 'view'));
        $this->assertTrue($user->hasPermission('players', 'edit'));
    }
}
