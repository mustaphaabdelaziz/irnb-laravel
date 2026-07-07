<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SharedPermissionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function effective_permissions_are_shared(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view', 'edit']]]);
        $user = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.players', ['view', 'edit'])
                ->where('auth.isSuperadmin', false)
                ->where('auth.isAdmin', false));
    }

    #[Test]
    public function superadmin_flag_and_admin_derived_true(): void
    {
        $user = User::factory()->create(['privileges' => ['superadmin'], 'approved' => true, 'email_verified_at' => now()]);
        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.isSuperadmin', true)
                ->where('auth.isAdmin', true));
    }
}
