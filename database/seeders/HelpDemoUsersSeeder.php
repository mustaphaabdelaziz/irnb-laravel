<?php

namespace Database\Seeders;

use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts, a custom role and some activity for the Help Center demo club.
 * Called only from the guarded HelpDemoSeeder, last. ActivityRecorder says it is
 * never called from seeders: this throwaway screenshot database is the one
 * exception, so the activity pages have something real to show.
 */
class HelpDemoUsersSeeder extends Seeder
{
    public const COACH = 'samir';

    public const ACCOUNTANT = 'nadia';

    public const SECRETARY = 'hanane';

    public const DISABLED = 'layla';

    public const ROLE_KEY = 'secretary';

    public const ROLE_NAME = 'كاتب عام';

    private CarbonImmutable $today;

    private CarbonImmutable $seasonStart;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->today = CarbonImmutable::today();
        $this->seasonStart = Season::current()->start();

        // DatabaseSeeder's AdminUserSeeder adds a stray "Admin User" (admin@irnb.local); nothing
        // in the demo refers to it, and it would show up in every users list shot.
        User::where('email', env('ADMIN_EMAIL', 'admin@irnb.local'))->where('username', '!=', HelpDemoSeeder::USERNAME)->delete();

        $secretary = Role::create([
            'key' => self::ROLE_KEY,
            'name' => ['ar' => self::ROLE_NAME, 'fr' => 'Secrétaire général', 'en' => 'General secretary'],
            'permissions' => [
                'players' => ['view', 'add', 'edit'],
                'documents' => ['view', 'add', 'edit'],
                'attendance' => ['view'],
            ],
            'is_system' => false,
        ]);
        $coachRole = Role::where('key', 'coach')->firstOrFail();
        $accountantRole = Role::where('key', 'accountant')->firstOrFail();

        $samir = $this->user(self::COACH, 'سمير', 'بوعزة', 'Male', $coachRole, ['0661234567'], $this->today->subDay()->setTime(18, 40));
        $nadia = $this->user(self::ACCOUNTANT, 'نادية', 'خليفي', 'Female', $accountantRole, ['0770987654'], $this->today->setTime(8, 15));
        $nadia->update(['permission_overrides' => ['grant' => ['players' => ['view']], 'revoke' => []]]);
        $this->user(self::SECRETARY, 'حنان', 'سعيدي', 'Female', $secretary, [], null);
        $this->user(self::DISABLED, 'ليلى', 'بن صالح', 'Female', $coachRole, [], $this->today->subDays(40)->setTime(17, 0), active: false);

        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();

        foreach ([1, 3, 6, 8] as $days) {
            $this->log($samir, ActivityAction::USER_LOGGED_IN, null, $days, 17);
        }
        // Held U17 sessions, newest first (HelpDemoAttendanceSeeder).
        $u17 = Category::where('name', HelpDemoAttendanceSeeder::FOOT_CATEGORY)->firstOrFail();
        $sessions = TrainingSession::query()
            ->where('category_id', $u17->id)
            ->where('state', SessionState::Held->value)
            ->orderByDesc('date')->orderByDesc('start_time')
            ->limit(4)->get()->values();
        foreach ([1, 3, 6, 8] as $i => $days) {
            // MarkRecorder's property: how many players were marked (the U17 demo roster is 4).
            $this->log($samir, ActivityAction::ATTENDANCE_MARKED, $sessions[$i] ?? null, $days, 19, ['count' => 4]);
        }

        foreach ([0, 2, 5, 7] as $days) {
            $this->log($nadia, ActivityAction::USER_LOGGED_IN, null, $days, 8);
        }
        // Hand entries of the current season (not tied to a subscription), oldest ids first.
        $entries = Transaction::query()
            ->whereNull('player_subscription_id')
            ->where('archived', false)
            ->where('transaction_date', '>=', $this->seasonStart->toDateString())
            ->orderBy('id')->limit(3)->get()->values();
        $this->log($demo, ActivityAction::USER_IMPERSONATED, $nadia, 2, 9);
        foreach ([2, 5, 7] as $i => $days) {
            $entry = $entries[$i] ?? null;
            // The same small properties TransactionController::store records.
            $log = $this->log($nadia, ActivityAction::TRANSACTION_RECORDED, $entry, $days, 10, [
                'amount' => $entry ? (float) $entry->amount : null,
                'type' => $entry?->transaction_type,
            ]);
            if ($days === 2) {
                // Done while the admin was logged in as her: the «بواسطة …» badge.
                $log->update(['impersonator_id' => $demo->id]);
            }
        }
    }

    private function user(string $username, string $first, string $last, string $gender, Role $role, array $phones, ?CarbonImmutable $loggedIn, bool $active = true): User
    {
        return User::create([
            'username' => $username,
            'email' => $username.'@example.invalid',
            'password' => Hash::make(HelpDemoSeeder::PASSWORD),
            'firstname' => $first,
            'lastname' => $last,
            'gender' => $gender,
            'phones' => $phones,
            'is_user' => true,
            'is_active' => $active,
            'approved' => true,
            'privileges' => ['user'],
            'role_id' => $role->id,
            'preferred_lng' => 'ar',
            'email_verified_at' => now(),
            'logged_in_at' => $loggedIn,
        ]);
    }

    private function log(User $user, string $action, ?Model $subject, int $daysBefore, int $hour, array $properties = []): ActivityLog
    {
        $log = ActivityRecorder::record($user, $action, $subject, $properties);
        $log->update(['occurred_at' => $this->today->subDays($daysBefore)->setTime($hour, 5)->max($this->seasonStart)]);

        return $log;
    }
}
