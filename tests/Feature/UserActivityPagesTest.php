<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Player;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Support\PermissionMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserActivityPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function log(User $user, string $action, string $at = '2026-10-10 09:00:00', ?array $properties = null, ?Model $subject = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
            'occurred_at' => $at,
        ]);
    }

    private function memberWithoutUsersView(): User
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);

        return User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);
    }

    #[Test]
    public function activity_routes_are_gated_by_users_view_and_profile_activity_is_unguarded(): void
    {
        $this->assertSame(['users', 'view'], PermissionMap::resolve('users.activity.index'));
        $this->assertSame(['users', 'view'], PermissionMap::resolve('users.activity.show'));
        $this->assertNull(PermissionMap::resolve('profile.activity'));
    }

    #[Test]
    public function admin_sees_the_comparison_with_rows_areas_and_period(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin']);
        $clerk = User::factory()->create(['name' => 'Clerk']);
        $this->log($clerk, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 1500]);
        $this->log($clerk, ActivityAction::PLAYER_REGISTERED);

        $this->actingAs($admin)->get(route('users.activity.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Activity/Index')
                ->where('period.period', 'month')
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-31')
                ->where('areas', array_keys(ActivityAction::AREAS))
                ->where('rows.0.user.name', 'Clerk')
                ->where('rows.0.payments.count', 1)
                ->where('rows.0.areas.players', 1)
                ->where('rows.0.total', 2)
            );
    }

    #[Test]
    public function period_query_parameters_reach_the_props(): void
    {
        $admin = User::factory()->admin()->create();
        $clerk = User::factory()->create();
        $this->log($clerk, ActivityAction::PLAYER_REGISTERED, '2026-09-05 10:00:00');

        $this->actingAs($admin)
            ->get(route('users.activity.index', ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.period', 'custom')
                ->where('period.from', '2026-09-01')
                ->where('period.to', '2026-09-30')
                ->where('rows.0.user.id', $clerk->id)
                ->where('rows.0.total', 1)
            );

        $this->actingAs($admin)
            ->get(route('users.activity.show', ['user' => $clerk->id, 'period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.from', '2026-09-01')
                ->where('summary.0.action', ActivityAction::PLAYER_REGISTERED)
                ->where('summary.0.count', 1)
            );
    }

    #[Test]
    public function admin_sees_a_users_summary_and_the_entries_of_the_chosen_action(): void
    {
        $admin = User::factory()->admin()->create();
        $clerk = User::factory()->create(['name' => 'Clerk']);
        $this->log($clerk, ActivityAction::PAYMENT_RECORDED, '2026-10-02 09:00:00', ['amount' => 1000]);
        $this->log($clerk, ActivityAction::PAYMENT_RECORDED, '2026-10-03 09:00:00', ['amount' => 500, 'backfilled' => true]);
        $this->log($clerk, ActivityAction::TASK_CREATED);

        $this->actingAs($admin)->get(route('users.activity.show', $clerk))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Activity/Show')
                ->where('user', ['id' => $clerk->id, 'name' => 'Clerk'])
                ->where('mine', false)
                ->has('summary', 2)
                ->where('summary.0.action', ActivityAction::PAYMENT_RECORDED)
                ->where('summary.0.count', 2)
                ->missing('entries')
            );

        $this->actingAs($admin)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => ActivityAction::PAYMENT_RECORDED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('action', ActivityAction::PAYMENT_RECORDED)
                ->has('entries.data', 2)
                ->where('entries.data.0.properties.backfilled', true)
                ->where('entries.data.1.properties.amount', 1000)
                ->where('entries.total', 2)
            );
    }

    #[Test]
    public function an_unknown_action_or_area_is_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $clerk = User::factory()->create();
        $this->log($clerk, ActivityAction::TASK_CREATED);

        $this->actingAs($admin)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => 'unknown', 'area' => 'nope']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('entries')
                ->where('action', null)
                ->where('area', null)
                ->has('summary', 1)
            );

        $this->actingAs($admin)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => ['x'], 'area' => 'board']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('entries')->where('area', 'board'));
    }

    #[Test]
    public function a_user_without_users_view_is_forbidden_on_both_pages(): void
    {
        $member = $this->memberWithoutUsersView();
        $other = User::factory()->create();

        $this->actingAs($member)->get(route('users.activity.index'))->assertForbidden();
        $this->actingAs($member)->get(route('users.activity.show', $other))->assertForbidden();
    }

    #[Test]
    public function my_activity_works_for_any_approved_user_and_shows_only_their_own_data(): void
    {
        $member = $this->memberWithoutUsersView();
        $other = User::factory()->create();
        $this->log($member, ActivityAction::DOCUMENT_RECEIVED);
        $this->log($other, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 900]);
        $this->log($other, ActivityAction::TASK_CREATED);

        $this->actingAs($member)->get(route('profile.activity', ['user' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Activity/Show')
                ->where('user.id', $member->id)
                ->where('mine', true)
                ->has('summary', 1)
                ->where('summary.0.action', ActivityAction::DOCUMENT_RECEIVED)
            );

        $this->actingAs($member)->get(route('profile.activity', ['user' => $other->id, 'action' => ActivityAction::PAYMENT_RECORDED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
    }

    private function memberWithRole(array $permissions): User
    {
        $role = Role::factory()->create(['permissions' => $permissions]);

        return User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);
    }

    private function player(): Player
    {
        return Player::create(['membership_id' => '7700000001', 'firstname' => 'Yacine', 'lastname' => 'Brahimi']);
    }

    private function transaction(): Transaction
    {
        return Transaction::create([
            'title' => 'Cotisation secrète',
            'amount' => 1500,
            'transaction_date' => '2026-10-10',
            'transaction_type' => 'income',
            'category' => 'donation',
            'payment_method' => 'cash',
            'status' => 'Paid',
            'fiscal_year' => 2026,
        ]);
    }

    #[Test]
    public function a_non_god_role_with_users_view_opens_both_pages(): void
    {
        $viewer = $this->memberWithRole(['users' => ['view']]);
        $other = User::factory()->create();

        $this->actingAs($viewer)->get(route('users.activity.index'))->assertOk();
        $this->actingAs($viewer)->get(route('users.activity.show', $other))->assertOk();
    }

    #[Test]
    public function entry_labels_and_links_are_neutral_for_modules_the_viewer_cannot_view(): void
    {
        $clerk = User::factory()->create();
        $player = $this->player();
        $tx = $this->transaction();
        $this->log($clerk, ActivityAction::PLAYER_REGISTERED, subject: $player);
        $this->log($clerk, ActivityAction::TRANSACTION_RECORDED, properties: ['amount' => 1500], subject: $tx);

        $usersOnly = $this->memberWithRole(['users' => ['view']]);

        $this->actingAs($usersOnly)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => ActivityAction::PLAYER_REGISTERED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.subject.url', null)
                ->where('entries.data.0.subject.neutral', true)
                ->where('entries.data.0.subject.label', fn (string $label) => ! str_contains($label, 'Yacine') && str_ends_with($label, '#'.$player->id))
            );

        $this->actingAs($usersOnly)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => ActivityAction::TRANSACTION_RECORDED]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.subject.url', null)
                ->where('entries.data.0.subject.label', fn (string $label) => ! str_contains($label, 'secrète'))
            );

        $withPlayers = $this->memberWithRole(['users' => ['view'], 'players' => ['view']]);

        $this->actingAs($withPlayers)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => ActivityAction::PLAYER_REGISTERED]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.subject.label', $player->fullname)
                ->where('entries.data.0.subject.url', route('players.show', $player))
                ->missing('entries.data.0.subject.neutral')
            );

        $this->actingAs($withPlayers)->get(route('users.activity.show', ['user' => $clerk->id, 'action' => ActivityAction::TRANSACTION_RECORDED]))
            ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.subject.url', null)->where('entries.data.0.subject.neutral', true));
    }

    #[Test]
    public function my_activity_is_neutral_for_modules_the_member_cannot_view(): void
    {
        $member = $this->memberWithRole(['board' => ['view']]);
        $player = $this->player();
        $this->log($member, ActivityAction::PLAYER_REGISTERED, subject: $player);

        $this->actingAs($member)->get(route('profile.activity', ['action' => ActivityAction::PLAYER_REGISTERED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.subject.url', null)
                ->where('entries.data.0.subject.neutral', true)
                ->where('entries.data.0.subject.label', fn (string $label) => ! str_contains($label, 'Yacine'))
            );
    }

    #[Test]
    public function entries_pagination_links_keep_the_period_and_the_action(): void
    {
        $admin = User::factory()->admin()->create();
        $clerk = User::factory()->create();
        foreach (range(1, 30) as $i) {
            $this->log($clerk, ActivityAction::TASK_CREATED, '2026-09-10 09:00:00');
        }

        $query = ['user' => $clerk->id, 'period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'action' => ActivityAction::TASK_CREATED];

        $this->actingAs($admin)->get(route('users.activity.show', $query))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 25)
                ->where('entries.next_page_url', function (string $url) {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

                    return $q['period'] === 'custom' && $q['from'] === '2026-09-01' && $q['to'] === '2026-09-30'
                        && $q['action'] === ActivityAction::TASK_CREATED && $q['page'] === '2';
                })
            );
    }

    #[Test]
    public function the_static_activity_path_is_not_captured_by_a_user_wildcard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/users/activity')->assertOk();
        $this->assertSame('users.activity.index', app('router')->getRoutes()->match(request()->create('/users/activity'))->getName());
    }
}
