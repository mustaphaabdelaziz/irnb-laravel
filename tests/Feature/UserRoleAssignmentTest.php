<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['privileges' => ['superadmin'], 'approved' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function superadmin_assigns_a_role_to_a_user(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $member = User::factory()->create(['privileges' => ['user'], 'email_verified_at' => now()]);

        $this->actingAs($this->superadmin())
            ->put(route('users.update', $member), [
                'name' => $member->name,
                'role_id' => $role->id,
                'permission_overrides' => ['grant' => ['finance' => ['view']]],
            ])->assertRedirect(route('users.index'));

        $member->refresh();
        $this->assertSame($role->id, $member->role_id);
        $this->assertTrue($member->hasPermission('players', 'view'));
        $this->assertTrue($member->hasPermission('finance', 'view'));
    }

    #[Test]
    public function non_superadmin_cannot_change_role(): void
    {
        $role = Role::factory()->create();
        $admin = User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
        $member = User::factory()->create(['privileges' => ['user'], 'email_verified_at' => now()]);

        $this->actingAs($admin)
            ->put(route('users.update', $member), [
                'name' => 'Renamed',
                'role_id' => $role->id,
            ])->assertRedirect();

        $member->refresh();
        $this->assertNull($member->role_id);          // role change ignored
        $this->assertSame('Renamed', $member->name);  // profile edit still works
    }
}
