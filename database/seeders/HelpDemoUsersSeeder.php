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
use RuntimeException;

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

        // The users list sorts newest first on created_at, and these rows are created within one
        // second: without fixed dates the order flips between runs and moves every users shot.
        // Order: samir, nadia, hanane, layla, demo.
        foreach ([HelpDemoSeeder::USERNAME => 60, self::DISABLED => 4, self::SECRETARY => 3, self::ACCOUNTANT => 2, self::COACH => 1] as $username => $minutes) {
            User::where('username', $username)->update(['created_at' => now()->subMinutes($minutes)]);
        }

        $this->attendance($demo, $samir);
        $this->finance($demo, $nadia);
    }

    /**
     * MarkRecorder already logged every mark as demo, at seed time. Hand the U17 ones to
     * the coach, then redate all of them to their session (end time, 19:30 if none).
     * One login per day he marked.
     */
    private function attendance(User $demo, User $samir): void
    {
        $u17 = Category::where('name', HelpDemoAttendanceSeeder::FOOT_CATEGORY)->firstOrFail();
        $marks = ActivityLog::query()
            ->where('action', ActivityAction::ATTENDANCE_MARKED)
            ->where('subject_type', (new TrainingSession)->getMorphClass())
            ->get();
        if ($marks->isEmpty()) {
            throw new RuntimeException('HelpDemoUsersSeeder needs the attendance marks of HelpDemoAttendanceSeeder.');
        }

        $sessions = TrainingSession::whereIn('id', $marks->pluck('subject_id'))->get()->keyBy('id');
        $days = [];
        foreach ($marks as $mark) {
            $session = $sessions[$mark->subject_id];
            $day = substr((string) $session->getRawOriginal('date'), 0, 10);
            $end = CarbonImmutable::parse($day.' '.($session->end_time ?: '19:30'));
            $mine = (int) $session->category_id === (int) $u17->id;
            $mark->update([
                'user_id' => $mine ? $samir->id : $mark->user_id,
                'occurred_at' => $this->clamp($end),
            ]);
            if ($mine) {
                $days[$end->toDateString()] = true;
            }
        }
        if ($days === []) {
            throw new RuntimeException('HelpDemoUsersSeeder found no marked U17 session for the coach.');
        }
        foreach (array_keys($days) as $day) {
            $this->log($samir, ActivityAction::USER_LOGGED_IN, null, CarbonImmutable::parse($day)->setTime(17, 5));
        }
    }

    /** Nadia's three newest hand entries, the logins on those days and today, one impersonated day. */
    private function finance(User $demo, User $nadia): void
    {
        $entries = Transaction::query()
            ->whereNull('player_subscription_id')
            ->where('archived', false)
            ->where('transaction_date', '>=', $this->seasonStart->toDateString())
            ->where('transaction_date', '<=', $this->today->toDateString())
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->limit(3)->get()->values();
        if ($entries->count() < 3) {
            throw new RuntimeException('HelpDemoUsersSeeder needs three current-season hand transactions.');
        }

        Transaction::whereKey($entries->pluck('id'))->update(['recorded_by_user_id' => $nadia->id]);

        $loginDays = $entries->map(fn ($e) => CarbonImmutable::parse($e->transaction_date)->toDateString())
            ->push($this->today->toDateString())->unique();
        foreach ($loginDays as $day) {
            $this->log($nadia, ActivityAction::USER_LOGGED_IN, null, CarbonImmutable::parse($day)->setTime(8, 5));
        }

        $middle = CarbonImmutable::parse($entries[1]->transaction_date);
        $this->log($demo, ActivityAction::USER_IMPERSONATED, $nadia, $middle->setTime(9, 5));
        foreach ($entries as $i => $entry) {
            // The same small properties TransactionController::store records.
            $log = $this->log($nadia, ActivityAction::TRANSACTION_RECORDED, $entry,
                CarbonImmutable::parse($entry->transaction_date)->setTime(10, 5), [
                    'amount' => (float) $entry->amount,
                    'type' => $entry->transaction_type,
                ]);
            if ($i === 1) {
                // Done while the admin was logged in as her: the «بواسطة …» badge.
                $log->update(['impersonator_id' => $demo->id]);
            }
        }
    }

    /** Inside the season and never in the future. */
    private function clamp(CarbonImmutable $at): CarbonImmutable
    {
        return $at->max($this->seasonStart)->min(CarbonImmutable::now());
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
            'logged_in_at' => $loggedIn?->min(CarbonImmutable::now()),
        ]);
    }

    private function log(User $user, string $action, ?Model $subject, CarbonImmutable $at, array $properties = []): ActivityLog
    {
        $log = ActivityRecorder::record($user, $action, $subject, $properties);
        $log->update(['occurred_at' => $this->clamp($at)]);

        return $log;
    }
}
