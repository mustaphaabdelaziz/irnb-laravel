<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_without_role_is_denied_module_pages(): void
    {
        $user = User::factory()->create(['privileges' => ['user'], 'approved' => true, 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('players.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.index'))->assertForbidden();
    }

    #[Test]
    public function user_with_role_sees_only_granted_modules(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $user = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($user)->get(route('players.index'))->assertOk();
        $this->actingAs($user)->get(route('finance.index'))->assertForbidden();
    }

    #[Test]
    public function dashboard_and_profile_stay_open(): void
    {
        $user = User::factory()->create(['privileges' => ['user'], 'approved' => true, 'email_verified_at' => now()]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }
}
