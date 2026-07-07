<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['privileges' => ['superadmin'], 'approved' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function superadmin_can_create_a_role(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('roles.store'), [
                'name' => ['en' => 'Coach', 'fr' => 'Coach', 'ar' => 'مدرب'],
                'permissions' => ['players' => ['view', 'edit']],
            ])->assertRedirect();

        $this->assertDatabaseHas('roles', ['key' => 'coach']);
        $this->assertSame(['view', 'edit'], Role::where('key', 'coach')->first()->permissions['players']);
    }

    #[Test]
    public function invalid_module_or_action_is_rejected(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('roles.store'), [
                'name' => ['en' => 'Bad'],
                'permissions' => ['nope' => ['fly']],
            ])->assertSessionHasErrors('permissions');
    }

    #[Test]
    public function system_roles_cannot_be_deleted(): void
    {
        $role = Role::factory()->create(['is_system' => true, 'key' => 'administrator']);
        $this->actingAs($this->superadmin())->delete(route('roles.destroy', $role))->assertRedirect();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    #[Test]
    public function non_superadmin_cannot_manage_roles(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'approved' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('roles.store'), ['name' => ['en' => 'X']])->assertForbidden();
    }
}
