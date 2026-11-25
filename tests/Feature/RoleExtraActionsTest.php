<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleExtraActionsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['privileges' => ['superadmin']]);
    }

    #[Test]
    public function approve_exists_only_on_payment_orders(): void
    {
        $all = Role::allPermissions();

        $this->assertSame(['view', 'add', 'edit', 'delete', 'approve'], $all['payment_orders']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['suppliers']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $all['players']);
        $this->assertContains('payment_orders', Role::MODULE_GROUPS['finance']);
        $this->assertContains('suppliers', Role::MODULE_GROUPS['config']);
    }

    #[Test]
    public function the_role_editor_saves_approve_on_payment_orders(): void
    {
        $role = Role::factory()->create(['permissions' => []]);

        $this->actingAs($this->superadmin())->put(route('roles.update', $role), [
            'name' => ['en' => 'Treasurer'],
            'permissions' => ['payment_orders' => ['view', 'approve']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['view', 'approve'], $role->fresh()->permissions['payment_orders']);
    }

    #[Test]
    public function approve_on_another_module_is_refused(): void
    {
        $role = Role::factory()->create(['permissions' => []]);

        $this->actingAs($this->superadmin())->put(route('roles.update', $role), [
            'name' => ['en' => 'Coach'],
            'permissions' => ['players' => ['view', 'approve']],
        ])->assertSessionHasErrors('permissions');
    }

    #[Test]
    public function a_user_with_approve_has_the_permission(): void
    {
        $role = Role::factory()->create(['permissions' => ['payment_orders' => ['view', 'approve']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->assertTrue($user->hasPermission('payment_orders', 'approve'));
        $this->assertFalse($user->hasPermission('payment_orders', 'edit'));
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_new_modules_only(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);

        (require database_path('migrations/2026_11_25_100000_grant_supplier_and_payment_order_permissions.php'))->up();

        $this->assertSame(['view', 'add', 'edit', 'delete', 'approve'], $system->fresh()->permissions['payment_orders']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['suppliers']);
        $this->assertArrayNotHasKey('payment_orders', $other->fresh()->permissions);
        $this->assertArrayNotHasKey('suppliers', $other->fresh()->permissions);
    }
}
