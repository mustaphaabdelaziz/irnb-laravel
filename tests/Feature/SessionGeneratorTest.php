<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class SessionGeneratorTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function schedule(int $categoryId, int $weekday, array $extra = []): TrainingSchedule
    {
        return TrainingSchedule::create($extra + [
            'category_id' => $categoryId, 'weekday' => $weekday, 'start_time' => '18:00', 'end_time' => '19:30',
            'valid_from' => '2026-01-01',
        ]);
    }

    private function dates(int $categoryId): array
    {
        return TrainingSession::where('category_id', $categoryId)->orderBy('date')->pluck('date')->all();
    }

    #[Test]
    public function it_creates_one_planned_session_per_scheduled_weekday_and_is_idempotent(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, 1); // Mondays
        $this->schedule($u15->id, 3); // Wednesdays

        // October 2026: Mondays 5,12,19,26 and Wednesdays 7,14,21,28.
        $this->assertSame(8, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));
        $this->assertSame(0, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));

        $this->assertSame(
            ['2026-10-05', '2026-10-07', '2026-10-12', '2026-10-14', '2026-10-19', '2026-10-21', '2026-10-26', '2026-10-28'],
            $this->dates($u15->id),
        );
        $first = TrainingSession::orderBy('date')->first();
        $this->assertSame(SessionKind::Regular, $first->kind);
        $this->assertSame(SessionState::Planned, $first->state);
        $this->assertSame('18:00', $first->start_time);
        $this->assertNotNull($first->schedule_id);
    }

    #[Test]
    public function closures_and_validity_ranges_are_respected(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, 1, ['valid_to' => '2026-10-20']);
        $this->schedule($u15->id, 1, ['valid_from' => '2026-10-21', 'start_time' => '17:00']);
        ClubClosure::create(['start_date' => '2026-10-10', 'end_date' => '2026-10-13', 'reason' => 'Holiday']);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        $slots = TrainingSession::orderBy('date')->get()->map(fn ($s) => "$s->date $s->start_time")->all();
        $this->assertSame(['2026-10-05 18:00', '2026-10-19 18:00', '2026-10-26 17:00'], $slots);
    }

    #[Test]
    public function cancelled_and_moved_sessions_are_not_recreated(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, 1);
        $generator = app(SessionGenerator::class);
        $generator->forMonth($u15->id, 2026, 10);

        TrainingSession::where('date', '2026-10-05')->update(['state' => SessionState::Cancelled->value, 'cancel_reason' => 'Rain']);
        TrainingSession::where('date', '2026-10-12')->update(['date' => '2026-10-13', 'moved_from' => '2026-10-12']);

        $this->assertSame(0, $generator->forMonth($u15->id, 2026, 10));
        $this->assertSame(['2026-10-05', '2026-10-13', '2026-10-19', '2026-10-26'], $this->dates($u15->id));
    }

    #[Test]
    public function another_category_is_untouched(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $this->schedule($u17->id, 1);

        $this->assertSame(0, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));
        $this->assertSame(0, TrainingSession::count());
    }

    #[Test]
    public function a_moved_session_stays_moved_after_its_schedule_is_replaced(): void
    {
        $u15 = $this->category();
        $schedule = $this->schedule($u15->id, 1); // Mondays
        $generator = app(SessionGenerator::class);

        // Generate October 2026; Mondays: 5, 12, 19, 26
        $generator->forMonth($u15->id, 2026, 10);

        // Move the 2026-10-12 session to 2026-10-13
        TrainingSession::where('date', '2026-10-12')->update(['date' => '2026-10-13', 'moved_from' => '2026-10-12']);

        // Delete the schedule (schedule_id becomes NULL)
        $schedule->delete();

        // Create a new Monday schedule
        $this->schedule($u15->id, 1);

        // Regenerate October: must not recreate the session on 2026-10-12
        $this->assertSame(0, $generator->forMonth($u15->id, 2026, 10));

        // Assert: no session on 2026-10-12, moved session still on 2026-10-13
        $dates = $this->dates($u15->id);
        $this->assertNotContains('2026-10-12', $dates);
        $this->assertContains('2026-10-13', $dates);
    }
}
