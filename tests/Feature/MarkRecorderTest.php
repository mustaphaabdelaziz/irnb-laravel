<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class MarkRecorderTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function makeSession(Category $category, string $date = '2026-10-05'): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function the_expected_roster_is_the_category_on_that_date(): void
    {
        $u15 = $this->category();
        $stays = $this->player($u15);
        $this->player($u15, ['archived' => true]);
        // Player::booted() clears left_at unless the status is "left" (seeded by migrations).
        $left = Player::leftStatusId();
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-01']);
        $leavesLater = $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-20']);
        $this->player($this->category('U17'));

        $ids = app(Roster::class)->expected($u15->id, '2026-10-05')->pluck('id')->all();

        $this->assertSame([$stays->id, $leavesLater->id], $ids);
    }

    #[Test]
    public function saving_stores_marks_normalises_fields_and_holds_the_session(): void
    {
        $u15 = $this->category();
        [$a, $b, $c] = [$this->player($u15), $this->player($u15), $this->player($u15)];
        $session = $this->makeSession($u15);
        $admin = $this->admin();

        $count = app(MarkRecorder::class)->save($session, [
            ['player_id' => $a->id, 'status' => 'late', 'minutes' => 12, 'reason' => 'injury'],
            ['player_id' => $b->id, 'status' => 'absent_excused', 'minutes' => 5, 'reason' => 'school', 'note' => 'Exam'],
            ['player_id' => $c->id, 'status' => 'present', 'note' => ''],
        ], $admin, ['coach' => 'Karim', 'theme' => 'Endurance', 'notes' => null]);

        $this->assertSame(3, $count);
        $marks = $session->attendances()->get()->keyBy('player_id');
        $this->assertSame(12, $marks[$a->id]->minutes);
        $this->assertNull($marks[$a->id]->reason);
        $this->assertNull($marks[$b->id]->minutes);
        $this->assertSame(AbsenceReason::School, $marks[$b->id]->reason);
        $this->assertSame('Exam', $marks[$b->id]->note);
        $this->assertNull($marks[$c->id]->note);
        $this->assertSame($admin->id, $marks[$c->id]->recorded_by);

        $session->refresh();
        $this->assertSame(SessionState::Held, $session->state);
        $this->assertSame('Karim', $session->coach);
        $this->assertSame(1, ActivityLog::where('action', 'attendance_marked')->count());
    }

    #[Test]
    public function saving_again_updates_marks_and_drops_removed_players(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->makeSession($u15);
        $recorder = app(MarkRecorder::class);

        $recorder->save($session, [
            ['player_id' => $a->id, 'status' => 'present'],
            ['player_id' => $b->id, 'status' => 'present'],
        ], null, ['coach' => 'Karim']);
        $recorder->save($session, [['player_id' => $a->id, 'status' => 'absent_unexcused']], null);

        $this->assertSame([$a->id], $session->attendances()->pluck('player_id')->all());
        $this->assertSame(AttendanceStatus::AbsentUnexcused, $session->attendances()->first()->status);
        $this->assertSame('Karim', $session->fresh()->coach); // null log keeps it
    }

    #[Test]
    public function once_marked_the_session_roster_is_frozen(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $session = $this->makeSession($u15);
        app(MarkRecorder::class)->save($session, [['player_id' => $a->id, 'status' => 'present']], null);

        $newcomer = $this->player($u15);
        $a->update(['category_id' => $this->category('U17')->id]);

        $this->assertSame([$a->id], app(Roster::class)->forSession($session)->pluck('id')->all());
        $this->assertSame([$newcomer->id], app(Roster::class)->forSession($this->makeSession($u15, '2026-10-06'))->pluck('id')->all());
    }

    #[Test]
    public function a_cancelled_session_cannot_be_marked(): void
    {
        $u15 = $this->category();
        $session = $this->makeSession($u15);
        $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $this->expectException(ValidationException::class);
        app(MarkRecorder::class)->save($session, [['player_id' => $this->player($u15)->id, 'status' => 'present']], null);
    }

    #[Test]
    public function saving_no_players_is_rejected_and_keeps_the_frozen_roster(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $session = $this->makeSession($u15);
        app(MarkRecorder::class)->save($session, [['player_id' => $a->id, 'status' => 'present']], null);

        try {
            app(MarkRecorder::class)->save($session, [], null);
            $this->fail('ValidationException should have been thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('marks', $e->errors());
        }

        $this->assertSame([$a->id], $session->attendances()->pluck('player_id')->all());
    }
}
