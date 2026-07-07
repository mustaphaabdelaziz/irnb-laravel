<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserPermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_role_means_no_permissions(): void
    {
        $user = User::factory()->create(['privileges' => ['user']]);
        $this->assertSame([], $user->effectivePermissions());
        $this->assertFalse($user->hasPermission('players', 'view'));
    }

    #[Test]
    public function role_matrix_is_returned(): void
    {
        $role = Role::factory()->create(['permissions' => ['finance' => ['view', 'edit']]]);
        $user = User::factory()->create(['role_id' => $role->id, 'privileges' => ['user']]);

        $this->assertTrue($user->hasPermission('finance', 'edit'));
        $this->assertFalse($user->hasPermission('finance', 'delete'));
    }

    #[Test]
    public function overrides_grant_and_revoke(): void
    {
        $role = Role::factory()->create(['permissions' => ['finance' => ['view', 'edit']]]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'privileges' => ['user'],
            'permission_overrides' => [
                'grant' => ['players' => ['view']],
                'revoke' => ['finance' => ['edit']],
            ],
        ]);

        $this->assertTrue($user->hasPermission('players', 'view'));   // granted
        $this->assertTrue($user->hasPermission('finance', 'view'));   // kept
        $this->assertFalse($user->hasPermission('finance', 'edit'));  // revoked
    }

    #[Test]
    public function god_admin_has_everything(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin']]);
        $super = User::factory()->create(['privileges' => ['superadmin']]);

        $this->assertTrue($admin->hasPermission('settings', 'delete'));
        $this->assertTrue($super->hasPermission('board', 'add'));
        $this->assertTrue($super->isSuperadmin());
        $this->assertFalse($admin->isSuperadmin());
    }
}
