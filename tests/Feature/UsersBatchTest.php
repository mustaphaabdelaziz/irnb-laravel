<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityPeriod;
use App\Services\Activity\ActivityReport;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * October users batch: computed names, "log in as", and activity recorded
 * for edits, deletions, sign-ins and settings, with chart data.
 */
class UsersBatchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $extra = []): User
    {
        return User::factory()->admin()->create($extra);
    }

    // ── computed name ────────────────────────────────────────────────

    #[Test]
    public function the_name_is_computed_from_firstname_and_lastname(): void
    {
        $this->actingAs($this->admin())->post(route('users.store'), [
            'username' => 'amine',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'firstname' => 'Amine',
            'lastname' => 'Benali',
            'name' => 'Ignored',
        ])->assertSessionHasNoErrors();

        $user = User::where('username', 'amine')->sole();
        $this->assertSame('Amine Benali', $user->name);

        $user->update(['lastname' => 'Saadi']);
        $this->assertSame('Amine Saadi', $user->fresh()->name);
    }

    #[Test]
    public function firstname_and_lastname_are_required(): void
    {
        $this->actingAs($this->admin())->post(route('users.store'), [
            'username' => 'amine',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertSessionHasErrors(['firstname', 'lastname']);
    }

    #[Test]
    public function the_split_migration_fills_first_and_last_names_from_the_old_name(): void
    {
        $two = User::factory()->create(['name' => 'Karim Ben Saad', 'firstname' => null, 'lastname' => null]);
        $one = User::factory()->create(['name' => 'Yacine', 'firstname' => null, 'lastname' => null]);
        $kept = User::factory()->create(['name' => 'X Y', 'firstname' => 'Already', 'lastname' => 'Set']);

        (require database_path('migrations/2026_10_06_100000_split_user_names.php'))->up();

        $this->assertSame(['Karim', 'Ben Saad'], [$two->fresh()->firstname, $two->fresh()->lastname]);
        $this->assertSame(['Yacine', null], [$one->fresh()->firstname, $one->fresh()->lastname]);
        $this->assertSame('Already', $kept->fresh()->firstname);
    }

    // ── access comes from roles only ─────────────────────────────────

    #[Test]
    public function retiring_the_admin_privilege_keeps_roles_and_gives_roleless_admins_the_administrator_role(): void
    {
        $administrator = Role::factory()->create(['key' => 'administrator', 'permissions' => Role::allPermissions()]);
        $accountant = Role::factory()->create(['key' => 'accountant', 'permissions' => ['finance' => ['view']]]);

        $withRole = User::factory()->create(['privileges' => ['admin'], 'role_id' => $accountant->id]);
        $withoutRole = User::factory()->create(['privileges' => ['admin'], 'role_id' => null]);
        $owner = User::factory()->create(['privileges' => ['superadmin', 'admin']]);
        $plain = User::factory()->create(['privileges' => ['user'], 'role_id' => null]);

        (require database_path('migrations/2026_10_07_100000_retire_admin_privilege.php'))->up();

        $this->assertSame(['user'], $withRole->fresh()->privileges);
        $this->assertSame($accountant->id, $withRole->fresh()->role_id);
        $this->assertFalse($withRole->fresh()->hasPermission('players', 'view')); // the role governs now

        $this->assertSame($administrator->id, $withoutRole->fresh()->role_id);
        $this->assertTrue($withoutRole->fresh()->hasPermission('players', 'view'));

        $this->assertSame(['superadmin'], $owner->fresh()->privileges);
        $this->assertNull($plain->fresh()->role_id);
    }

    #[Test]
    public function the_edit_form_cannot_set_privileges(): void
    {
        $member = User::factory()->create(['privileges' => ['user']]);

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))->put(route('users.update', $member), [
            'firstname' => 'A', 'lastname' => 'B', 'privileges' => ['admin'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['user'], $member->fresh()->privileges);
    }

    #[Test]
    public function the_users_list_filters_by_role_superadmin_or_no_role(): void
    {
        $coach = Role::factory()->create(['key' => 'coach']);
        User::factory()->create(['username' => 'u-coach', 'role_id' => $coach->id]);
        User::factory()->create(['username' => 'u-none', 'role_id' => null]);
        User::factory()->create(['username' => 'u-owner', 'privileges' => ['superadmin']]);

        $viewer = User::factory()->create(['privileges' => ['superadmin'], 'username' => 'zz-viewer']);
        $names = fn (array $role) => collect($this->actingAs($viewer)
            ->get(route('users.index', ['role' => $role]))->viewData('page')['props']['users']['data'])
            ->pluck('username')->reject(fn ($u) => $u === 'zz-viewer')->sort()->values()->all();

        $this->assertSame(['u-coach'], $names([(string) $coach->id]));
        $this->assertContains('u-none', $names(['none']));
        $this->assertNotContains('u-coach', $names(['none']));
        $this->assertSame(['u-owner'], array_values(array_filter($names(['superadmin']), fn ($u) => str_starts_with($u, 'u-'))));
        // An unknown value is no filter at all.
        $this->assertContains('u-coach', $names(['bogus']));
    }

    #[Test]
    public function the_users_list_shows_the_assigned_role(): void
    {
        $role = Role::factory()->create(['key' => 'coach', 'name' => ['en' => 'Coach']]);
        User::factory()->create(['role_id' => $role->id, 'username' => 'coachy']);

        $this->actingAs($this->admin())->get(route('users.index', ['search' => 'coachy']))
            ->assertInertia(fn (Assert $page) => $page->where('users.data.0.role.key', 'coach'));
    }

    // ── log in as ────────────────────────────────────────────────────

    #[Test]
    public function an_admin_logs_in_as_a_user_and_comes_back(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['firstname' => 'Sara', 'lastname' => 'Ali']);

        $this->actingAs($admin)
            ->post(route('users.impersonate', $member))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $member->id)
            ->where('auth.impersonator.id', $admin->id)
            ->where('auth.canImpersonate', false));

        $this->post(route('impersonate.leave'))->assertRedirect(route('users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => ActivityAction::USER_IMPERSONATED,
            'subject_id' => $member->id,
        ]);
    }

    #[Test]
    public function work_done_while_logged_in_as_someone_keeps_the_real_person(): void
    {
        $admin = $this->admin();
        $member = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.impersonate', $member));
        $this->post(route('categories.store'), ['name' => 'U17', 'code' => 'U17'])->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', ActivityAction::SETTING_ITEM_CREATED)->sole();
        $this->assertSame($member->id, $log->user_id);
        $this->assertSame($admin->id, $log->impersonator_id);
    }

    #[Test]
    public function log_in_as_is_refused_where_it_must_be(): void
    {
        $admin = $this->admin();
        $superadmin = User::factory()->create(['privileges' => ['superadmin']]);
        $disabled = User::factory()->create(['is_active' => false]);

        $this->actingAs($admin)->post(route('users.impersonate', $superadmin))->assertSessionHas('error', 'flash.impersonate_superadmin');
        $this->actingAs($admin)->post(route('users.impersonate', $admin))->assertSessionHas('error', 'flash.impersonate_self');
        $this->actingAs($admin)->post(route('users.impersonate', $disabled))->assertSessionHas('error', 'flash.impersonate_inactive');
        $this->assertAuthenticatedAs($admin);

        // A role that may only view users cannot (its route needs users/edit,
        // and the controller checks again).
        $viewer = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['users' => ['view']]])->id,
        ]);
        $target = User::factory()->create();
        $this->actingAs($viewer)->post(route('users.impersonate', $target))->assertForbidden();
        $this->assertAuthenticatedAs($viewer);
    }

    #[Test]
    public function a_role_that_may_edit_users_can_log_in_as_someone(): void
    {
        $editor = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['users' => ['view', 'edit']]])->id,
        ]);
        $target = User::factory()->create();

        $this->actingAs($editor)->post(route('users.impersonate', $target))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($target);
    }

    #[Test]
    public function nobody_but_a_superadmin_logs_in_as_someone_with_more_rights(): void
    {
        $editor = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['users' => ['view', 'edit']]])->id,
        ]);
        $treasurer = User::factory()->create([
            'role_id' => Role::factory()->create(['permissions' => ['finance' => ['view']]])->id,
        ]);

        $this->actingAs($editor)->post(route('users.impersonate', $treasurer))
            ->assertSessionHas('error', 'flash.impersonate_more_rights');
        $this->assertAuthenticatedAs($editor);

        $this->actingAs(User::factory()->create(['privileges' => ['superadmin']]))
            ->post(route('users.impersonate', $treasurer))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($treasurer);
    }

    #[Test]
    public function log_in_as_cannot_be_chained(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $member = User::factory()->create();

        $this->actingAs($admin)->post(route('users.impersonate', $otherAdmin));
        $this->post(route('users.impersonate', $member))->assertSessionHas('error', 'flash.impersonate_nested');
        $this->assertAuthenticatedAs($otherAdmin);
    }

    // ── recorded operations ─────────────────────────────────────────

    #[Test]
    public function every_mapped_route_exists_and_every_mapped_action_is_known(): void
    {
        foreach (config('activity.routes') as $name => $entry) {
            $this->assertTrue(Route::has($name), "Route [{$name}] in config/activity.php does not exist.");
            $this->assertContains(((array) $entry)[0], ActivityAction::ALL, "Unknown action for [{$name}].");
        }
    }

    #[Test]
    public function a_successful_edit_is_recorded_with_its_subject(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $member), ['firstname' => 'A', 'lastname' => 'B'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => ActivityAction::USER_UPDATED,
            'subject_type' => $member->getMorphClass(),
            'subject_id' => $member->id,
            'impersonator_id' => null,
        ]);
    }

    #[Test]
    public function failed_or_refused_writes_are_not_recorded(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        // Validation error.
        $this->actingAs($admin)->put(route('users.update', $member), ['firstname' => ''])->assertSessionHasErrors('firstname');
        // Refusal flashed by the controller.
        $this->actingAs($admin)->post(route('users.toggleActive', $admin))->assertSessionHas('error');

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function settings_list_changes_carry_the_list_they_belong_to(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'U15', 'code' => 'U15'])->assertSessionHasNoErrors();

        $category = Category::where('name', 'U15')->sole();
        $this->actingAs($admin)->delete(route('categories.destroy', $category));

        $this->assertSame(
            [[ActivityAction::SETTING_ITEM_CREATED, ['kind' => 'categories']], [ActivityAction::SETTING_ITEM_DELETED, ['kind' => 'categories']]],
            ActivityLog::orderBy('id')->get()->map(fn ($l) => [$l->action, $l->properties])->all(),
        );
    }

    #[Test]
    public function logins_and_logouts_are_recorded(): void
    {
        $user = User::factory()->create(['username' => 'karim']);

        $this->post('/login', ['username' => 'karim', 'password' => 'password']);
        $this->assertNotNull($user->fresh()->logged_in_at);
        $this->post('/logout');

        $this->assertSame(
            [ActivityAction::USER_LOGGED_IN, ActivityAction::USER_LOGGED_OUT],
            ActivityLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    // ── charts ───────────────────────────────────────────────────────

    #[Test]
    public function charts_bucket_events_per_day_and_rank_actions(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $user = User::factory()->create();
        $other = User::factory()->create();

        $log = fn (User $u, string $action, string $at) => ActivityLog::create([
            'user_id' => $u->id, 'action' => $action, 'occurred_at' => $at,
        ]);
        $log($user, ActivityAction::USER_LOGGED_IN, '2026-10-02 08:00:00');
        $log($user, ActivityAction::USER_LOGGED_IN, '2026-10-02 18:00:00');
        $log($user, ActivityAction::PLAYER_UPDATED, '2026-10-05 10:00:00');
        $log($other, ActivityAction::PLAYER_UPDATED, '2026-10-05 11:00:00');

        $period = ActivityPeriod::fromRequest(Request::create('/activity'));
        $all = ActivityReport::charts($period);

        $this->assertSame('day', $all['unit']);
        $this->assertSame(31, count($all['buckets']));
        $this->assertSame(2, $all['timeline']['access'][1]);   // 2 Oct
        $this->assertSame(2, $all['timeline']['players'][4]);  // 5 Oct
        $this->assertEquals(['access' => 2, 'players' => 2], array_filter($all['areas']));

        $mine = ActivityReport::charts($period, $user->id);
        $this->assertSame(
            [['action' => ActivityAction::USER_LOGGED_IN, 'area' => 'access', 'count' => 2], ['action' => ActivityAction::PLAYER_UPDATED, 'area' => 'players', 'count' => 1]],
            $mine['actions'],
        );

        $this->actingAs($this->admin())->get(route('users.activity.index'))
            ->assertInertia(fn (Assert $page) => $page->has('charts.timeline.settings')->has('charts.actions', 2));
    }

    #[Test]
    public function a_long_period_counts_per_week(): void
    {
        $period = ActivityPeriod::custom('2026-01-01', '2026-06-30');
        $this->assertSame('week', ActivityReport::charts($period)['unit']);
    }
}
