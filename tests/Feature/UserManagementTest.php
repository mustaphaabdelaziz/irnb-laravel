<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    private function superadmin(): User
    {
        return User::factory()->create([
            'privileges' => ['superadmin'],
            'approved' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function a_superadmin_can_reset_another_users_password(): void
    {
        $target = User::factory()->create(['password' => Hash::make('old'), 'email_verified_at' => now()]);

        $this->actingAs($this->superadmin())
            ->post(route('users.password', $target), [
                'password' => 'brand-new-pass-123',
                'password_confirmation' => 'brand-new-pass-123',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.password_reset');

        $this->assertTrue(Hash::check('brand-new-pass-123', $target->fresh()->password));
    }

    #[Test]
    public function resetting_a_password_clears_the_remember_token(): void
    {
        $target = User::factory()->create(['remember_token' => 'still-valid', 'email_verified_at' => now()]);

        $this->actingAs($this->superadmin())->post(route('users.password', $target), [
            'password' => 'brand-new-pass-123',
            'password_confirmation' => 'brand-new-pass-123',
        ])->assertRedirect();

        $this->assertNull($target->fresh()->remember_token);
    }

    #[Test]
    public function a_password_reset_must_be_confirmed(): void
    {
        $target = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($this->superadmin())->post(route('users.password', $target), [
            'password' => 'brand-new-pass-123',
            'password_confirmation' => 'does-not-match',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function a_non_superadmin_cannot_reset_a_password(): void
    {
        // An ordinary admin (has users access, not a superadmin) must not be
        // able to set another account's password — that is account takeover.
        $target = User::factory()->create(['password' => Hash::make('keep-me'), 'email_verified_at' => now()]);

        $this->actingAs($this->admin())->post(route('users.password', $target), [
            'password' => 'hijacked-000', 'password_confirmation' => 'hijacked-000',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('keep-me', $target->fresh()->password));
    }

    #[Test]
    public function a_user_can_be_disabled_and_re_enabled(): void
    {
        $admin = $this->superadmin();
        $target = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('users.toggleActive', $target))
            ->assertRedirect()->assertSessionHas('success', 'flash.user_disabled');
        $this->assertFalse((bool) $target->fresh()->is_active);

        $this->actingAs($admin)->post(route('users.toggleActive', $target))
            ->assertSessionHas('success', 'flash.user_enabled');
        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    #[Test]
    public function you_cannot_disable_your_own_account(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('users.toggleActive', $admin))
            ->assertSessionHas('error', 'flash.cannot_disable_self');

        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    #[Test]
    public function a_superadmin_can_delete_another_superadmin_when_others_remain(): void
    {
        // The exact situation the user is in: several superadmins, wanting one.
        $keeper = $this->superadmin();
        $extra = $this->superadmin();
        $target = $this->superadmin();

        $this->actingAs($keeper)->delete(route('users.destroy', $target))
            ->assertRedirect()->assertSessionHas('success', 'flash.user_deleted');

        $this->assertNull($target->fresh());
        $this->assertNotNull($keeper->fresh());
        $this->assertNotNull($extra->fresh());
    }
}
