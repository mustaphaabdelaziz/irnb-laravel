<?php

namespace Tests\Feature\Help;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use Database\Seeders\HelpDemoAttendanceSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoAttendanceSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Status keys of one player's marks over held sessions of a category, newest first. */
    private function marks(int $playerId, int $categoryId): array
    {
        return Attendance::query()
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('attendances.player_id', $playerId)
            ->where('training_sessions.category_id', $categoryId)
            ->where('training_sessions.state', SessionState::Held->value)
            ->orderByDesc('training_sessions.date')
            ->pluck('attendances.status')
            ->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : (string) $s)
            ->all();
    }

    #[Test]
    public function the_demo_club_has_timetables_sessions_and_marks(): void
    {
        // A Wednesday in mid-season: U17 trains today, so session 1 is today.
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);

        // Ids the screenshots use as literals.
        $this->assertSame(2, Branch::where('name', 'Football')->value('id'));
        $this->assertSame(7, Category::where('name', Demo::FOOT_CATEGORY)->value('id'));
        $this->assertSame(11, Category::where('name', Demo::SWIM_CATEGORY)->value('id'));
        $this->assertSame(['أمين', 'رياض', 'عبد الرحمن', 'أنس'], Player::whereIn('id', [3, 4, 7, 21])->orderBy('id')->pluck('firstname')->all());

        $toMark = TrainingSession::findOrFail(1);
        $this->assertSame(SessionState::Planned, $toMark->state);
        $this->assertSame(SessionKind::Regular, $toMark->kind);
        $this->assertSame(7, (int) $toMark->category_id);
        $this->assertSame('2026-11-18', $toMark->date);

        $preseason = TrainingSession::findOrFail(2);
        $this->assertSame(SessionKind::Preseason, $preseason->kind);
        $this->assertSame(SessionState::Held, $preseason->state);
        $this->assertSame('2026-11-14', $preseason->date);
        $this->assertSame(Demo::PRESEASON_TITLE, $preseason->title);

        $cancelled = TrainingSession::findOrFail(3);
        $this->assertSame(SessionState::Cancelled, $cancelled->state);
        $this->assertSame(Demo::CANCEL_REASON, $cancelled->cancel_reason);
        $this->assertSame('2026-11-19', $cancelled->date);

        // Three weeks of timetable before today: 3 Mondays + 3 Wednesdays held for U17.
        $this->assertSame(6, TrainingSession::where('category_id', 7)->where('kind', SessionKind::Regular->value)->where('state', SessionState::Held->value)->count());
        $this->assertSame(6, TrainingSession::where('category_id', 11)->where('state', SessionState::Held->value)->count());
        $this->assertSame(0, TrainingSession::where('date', '<', '2026-10-28')->count());

        // Marks the stats-ranking and alerts-injuries articles quote (7 held U17 sessions, newest first).
        $this->assertSame(['absent_unexcused', 'absent_unexcused', 'absent_unexcused', 'present', 'present', 'present', 'present'], $this->marks(7, 7));
        $this->assertSame(['not_training', 'not_training'], array_slice($this->marks(4, 7), 0, 2));
        $this->assertSame('late', $this->marks(3, 7)[1]);
        $this->assertSame('absent_excused', $this->marks(21, 7)[4]);
        $this->assertSame(15, (int) Attendance::where('player_id', 3)->where('status', 'late')->value('minutes'));

        $this->assertSame(Demo::CLOSURE_REASON, ClubClosure::sole()->reason);
        $this->assertSame('2026-12-08', ClubClosure::sole()->start_date);
        $this->assertSame(Demo::PRESEASON_TARGET, (int) PreseasonTarget::sole()->target_count);
    }
}
