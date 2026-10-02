<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStreaksTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status,
        ], $extra));
    }

    private function streaks(?int $playerId = null): array
    {
        return app(AttendanceStats::class)->unexcusedStreaks('2026-10-01', '2026-10-31', $playerId);
    }

    #[Test]
    public function streaks_follow_the_session_order_and_any_other_status_breaks_them(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $b = $this->player($u15);
        $c = $this->player($u15);
        $e = $this->player($u15);
        $this->player($u15);   // no mark at all: not in the result
        // Created out of order on purpose: a streak follows date, start time, id.
        $s5late = $this->training($u15, '2026-10-05', ['start_time' => '20:00', 'end_time' => '21:00']);
        $s1 = $this->training($u15, '2026-10-01');
        $s7 = $this->training($u15, '2026-10-07');
        $s3 = $this->training($u15, '2026-10-03');
        $s5 = $this->training($u15, '2026-10-05');
        $s9 = $this->training($u15, '2026-10-09');
        $cancelled = $this->training($u15, '2026-10-06', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);
        $november = $this->training($u15, '2026-11-02');
        $unexcused = AttendanceStatus::AbsentUnexcused;

        // A: AN AN P AN AN AN (5th at 18:00 present, 5th at 20:00 unexcused), then November (outside).
        foreach ([$s1, $s3] as $training) {
            $this->mark($training, $a, $unexcused);
        }
        $this->mark($s5, $a, AttendanceStatus::Present);
        foreach ([$s5late, $s7, $s9, $november] as $training) {
            $this->mark($training, $a, $unexcused);
        }
        // B: AN AN AN, late (breaks), a cancelled AN (ignored), AN.
        foreach ([$s1, $s3, $s5] as $training) {
            $this->mark($training, $b, $unexcused);
        }
        $this->mark($s5late, $b, AttendanceStatus::Late, ['minutes' => 5]);
        $this->mark($cancelled, $b, $unexcused);
        $this->mark($s7, $b, $unexcused);
        // C: always present.
        foreach ([$s1, $s3] as $training) {
            $this->mark($training, $c, AttendanceStatus::Present);
        }
        // E: an excused absence breaks a streak too.
        $this->mark($s1, $e, $unexcused);
        $this->mark($s3, $e, AttendanceStatus::AbsentExcused, ['reason' => 'illness']);
        $this->mark($s5, $e, $unexcused);

        $streaks = $this->streaks();

        $this->assertSame([$a->id, $b->id, $c->id, $e->id], array_keys($streaks));
        $this->assertSame(['current' => 3, 'longest' => 3, 'last_date' => '2026-10-09'], $streaks[$a->id]);
        $this->assertSame(['current' => 1, 'longest' => 3, 'last_date' => '2026-10-07'], $streaks[$b->id]);
        $this->assertSame(['current' => 0, 'longest' => 0, 'last_date' => '2026-10-03'], $streaks[$c->id]);
        $this->assertSame(['current' => 1, 'longest' => 1, 'last_date' => '2026-10-05'], $streaks[$e->id]);
        $this->assertSame([$b->id], array_keys($this->streaks($b->id)));
    }

    #[Test]
    public function one_query_whatever_the_roster(): void
    {
        $u15 = $this->category();
        $trainings = [$this->training($u15, '2026-10-05'), $this->training($u15, '2026-10-07')];
        $add = function (int $n) use ($u15, $trainings): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                foreach ($trainings as $training) {
                    $this->mark($training, $player, AttendanceStatus::AbsentUnexcused);
                }
            }
        };
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->streaks();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // The scan itself, plus one for the custom codes (which statuses are unexcused or skipped).
        $add(3);
        $this->assertSame(2, $measure());
        $add(30);
        $this->assertSame(2, $measure());
    }
}
