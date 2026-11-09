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

        $this->assertSame(8, $count(Demo::COACH));
        $this->assertSame(4, $count(Demo::COACH, ActivityAction::ATTENDANCE_MARKED));
        $this->assertSame(7, $count(Demo::ACCOUNTANT));
        $this->assertSame(1, $count(HelpDemoSeeder::USERNAME, ActivityAction::USER_IMPERSONATED));

        $byAdmin = ActivityLog::where('user_id', $this->user(Demo::ACCOUNTANT)->id)
            ->where('impersonator_id', $this->user(HelpDemoSeeder::USERNAME)->id)->count();
        $this->assertSame(1, $byAdmin);

        $this->assertSame(0, ActivityLog::where('occurred_at', '>', now())->count());
        $this->assertSame(0, ActivityLog::where('occurred_at', '<', \App\Support\Season::current()->start())->count());
    }
}
