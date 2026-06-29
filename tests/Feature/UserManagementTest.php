<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function non_admins_cannot_access_user_management(): void
    {
        $user = User::factory()->create([
            'privileges' => ['user'],
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
    }

    #[Test]
    public function admin_can_approve_a_pending_member(): void
    {
        $pending = User::factory()->create([
            'approved' => false,
            'is_active' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.approve', $pending))
            ->assertRedirect();

        $pending->refresh();
        $this->assertTrue($pending->approved);
        $this->assertTrue($pending->is_active);
    }

    #[Test]
    public function admin_can_update_member_roles_and_status(): void
    {
        $member = User::factory()->create([
            'privileges' => ['user'],
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->put(route('users.update', $member), [
                'name' => 'Updated Name',
                'privileges' => ['user', 'admin'],
                'approved' => true,
                'is_active' => true,
            ])
            ->assertRedirect(route('users.index'));

        $member->refresh();
        $this->assertSame('Updated Name', $member->name);
        $this->assertContains('admin', $member->privileges);
        $this->assertTrue($member->approved);
    }

    #[Test]
    public function superadmin_accounts_cannot_be_deleted(): void
    {
        $superadmin = User::factory()->create([
            'privileges' => ['superadmin', 'admin'],
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $superadmin))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $superadmin->id]);
    }

    #[Test]
    public function admins_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
