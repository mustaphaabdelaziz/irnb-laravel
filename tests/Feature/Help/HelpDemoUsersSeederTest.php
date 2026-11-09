<?php

namespace Tests\Feature\Help;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoUsersSeeder as Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-season seed day so no activity entry is clamped to the season start.
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    #[Test]
    public function staff_accounts_have_the_expected_roles_and_states(): void
    {
        $this->assertSame(5, User::count());

        $coach = $this->user(Demo::COACH);
        $this->assertSame('coach', $coach->role->key);
        $this->assertTrue((bool) $coach->is_active);
        $this->assertSame('سمير بوعزة', $coach->name);

        $this->assertSame('accountant', $this->user(Demo::ACCOUNTANT)->role->key);
        $this->assertSame(Demo::ROLE_KEY, $this->user(Demo::SECRETARY)->role->key);
        $this->assertNull($this->user(Demo::SECRETARY)->logged_in_at);

        $disabled = $this->user(Demo::DISABLED);
        $this->assertFalse((bool) $disabled->is_active);
        $this->assertTrue((bool) $disabled->approved);

        // Nobody waits for approval (see the plan's owner decisions).
        $this->assertSame(0, User::where('approved', false)->count());
    }

    #[Test]
    public function the_custom_role_and_the_extra_right_exist(): void
    {
        $role = Role::where('key', Demo::ROLE_KEY)->firstOrFail();
        $this->assertFalse((bool) $role->is_system);
        $this->assertSame(Demo::ROLE_NAME, $role->name['ar']);
        $this->assertSame(['view', 'add', 'edit'], $role->permissions['players']);
        $this->assertSame(['view'], $role->permissions['attendance']);

        $nadia = $this->user(Demo::ACCOUNTANT);
        $this->assertSame(['view'], $nadia->permission_overrides['grant']['players']);
        $this->assertTrue($nadia->hasPermission('players', 'view'));
        $this->assertFalse($nadia->hasPermission('players', 'add'));
    }

    #[Test]
    public function activity_entries_are_spread_over_the_season(): void
    {
        $count = fn (string $u, ?string $action = null) => ActivityLog::where('user_id', $this->user($u)->id)
            ->when($action, fn ($q) => $q->where('action', $action))->count();

        // Seed day 2026-11-18: 7 marked U17 days, one login each, plus the marks themselves.
        $this->assertSame(14, $count(Demo::COACH));
        $this->assertSame(7, $count(Demo::COACH, ActivityAction::ATTENDANCE_MARKED));
        $this->assertSame(7, $count(HelpDemoSeeder::USERNAME));
        $this->assertSame(6, $count(HelpDemoSeeder::USERNAME, ActivityAction::ATTENDANCE_MARKED));
        $this->assertSame(7, $count(Demo::ACCOUNTANT));
        $this->assertSame(1, $count(HelpDemoSeeder::USERNAME, ActivityAction::USER_IMPERSONATED));

        $byAdmin = ActivityLog::where('user_id', $this->user(Demo::ACCOUNTANT)->id)
            ->where('impersonator_id', $this->user(HelpDemoSeeder::USERNAME)->id)->count();
        $this->assertSame(1, $byAdmin);

        $this->assertSame(0, ActivityLog::where('occurred_at', '>', now())->count());
        $this->assertSame(0, ActivityLog::where('occurred_at', '<', \App\Support\Season::current()->start())->count());
    }

    #[Test]
    public function marks_are_unique_per_session_and_every_subject_is_set(): void
    {
        $marks = ActivityLog::where('action', ActivityAction::ATTENDANCE_MARKED)->get();
        $this->assertSame(13, $marks->count());
        $this->assertSame($marks->count(), $marks->unique('subject_id')->count());
        $this->assertSame(0, ActivityLog::whereNull('subject_id')
            ->whereIn('action', [ActivityAction::ATTENDANCE_MARKED, ActivityAction::TRANSACTION_RECORDED])->count());

        // Nadia's entries and the transactions they point at agree.
        $nadia = $this->user(Demo::ACCOUNTANT);
        $this->assertSame(3, \App\Models\Transaction::where('recorded_by_user_id', $nadia->id)->count());
    }

    #[Test]
    public function nothing_is_dated_in_the_future_when_seeded_early_in_the_day(): void
    {
        $this->travelTo('2026-11-18 06:00:00');
        $this->refreshApplication();
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => HelpDemoSeeder::class]);

        $this->assertSame(0, ActivityLog::where('occurred_at', '>', now())->count());
        $this->assertSame(0, User::where('logged_in_at', '>', now())->count());
    }
}
