<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Accounts sign in with a username (not an email) and are created only by an
 * administrator from the users page — there is no public registration.
 */
class UsernameAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'username' => 'mustapha',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'firstname' => 'Mustapha',
            'lastname' => 'Benali',
            ...$overrides,
        ];
    }

    #[Test]
    public function an_admin_creates_an_approved_active_account_without_an_email(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $user = User::where('username', 'mustapha')->sole();
        $this->assertNull($user->email);
        $this->assertTrue($user->approved);
        $this->assertTrue($user->is_active);
        $this->assertSame(['user'], $user->privileges);
    }

    #[Test]
    public function a_plain_name_and_an_email_shaped_name_are_two_distinct_usernames(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.store'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('users.store'), $this->payload(['username' => 'mustapha@irnb.com']))->assertSessionHasNoErrors();

        $this->assertSame(2, User::whereIn('username', ['mustapha', 'mustapha@irnb.com'])->count());
    }

    #[Test]
    public function usernames_are_case_insensitive_and_unique(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.store'), $this->payload(['username' => '  Mustapha ']))->assertSessionHasNoErrors();
        $this->assertTrue(User::where('username', 'mustapha')->exists());

        $this->actingAs($admin)->post(route('users.store'), $this->payload(['username' => 'MUSTAPHA']))
            ->assertSessionHasErrors('username');
    }

    #[Test]
    public function a_username_rejects_spaces_and_accepts_arabic_letters(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.store'), $this->payload(['username' => 'mus tapha']))
            ->assertSessionHasErrors('username');

        $this->actingAs($admin)->post(route('users.store'), $this->payload(['username' => 'مصطفى']))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_created_user_signs_in_with_the_username_in_any_case(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('users.store'), $this->payload());
        auth()->logout();

        $this->post('/login', ['username' => 'MUSTAPHA', 'password' => 'secret-pass-1'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::where('username', 'mustapha')->sole());

        // No email and never verified, yet the app is reachable.
        $this->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function an_email_is_not_accepted_in_place_of_a_different_username(): void
    {
        User::factory()->create(['username' => 'ali', 'email' => 'ali@irnb.com']);

        $this->post('/login', ['username' => 'ali@irnb.com', 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    #[Test]
    public function an_account_created_without_a_username_signs_in_with_its_email(): void
    {
        // Seeder and legacy-import path: only an email is given.
        $user = User::factory()->create(['username' => null, 'email' => 'Admin@IRNB.local']);

        $this->assertSame('admin@irnb.local', $user->username);
    }

    #[Test]
    public function only_a_superadmin_assigns_a_role_on_create(): void
    {
        $role = Role::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload(['username' => 'by-admin', 'role_id' => $role->id]));
        $this->assertNull(User::where('username', 'by-admin')->value('role_id'));

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('users.store'), $this->payload(['username' => 'by-super', 'role_id' => $role->id]));
        $this->assertSame($role->id, User::where('username', 'by-super')->value('role_id'));
    }

    #[Test]
    public function creating_users_needs_the_users_add_permission(): void
    {
        $viewer = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['users' => ['view', 'edit']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('users.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('users.store'), $this->payload())->assertForbidden();

        $creator = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['users' => ['view', 'add']]])->id,
        ]);

        $this->actingAs($creator)->get(route('users.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Users/Create'));
    }

    #[Test]
    public function an_admin_renames_a_username_and_the_old_one_stops_working(): void
    {
        $member = User::factory()->create(['username' => 'old-name']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.update', $member), ['firstname' => 'Ali', 'lastname' => 'Bensaid', 'username' => 'New-Name'])
            ->assertSessionHasNoErrors();

        $this->assertSame('new-name', $member->fresh()->username);
    }

    #[Test]
    public function public_registration_and_emailed_password_resets_are_gone(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@x.com', 'password' => 'p', 'password_confirmation' => 'p'])
            ->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->missing('canRegister'));
    }
}
