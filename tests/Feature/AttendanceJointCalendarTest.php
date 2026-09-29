<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Services\Attendance\PreseasonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceJointCalendarTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function a_category_calendar_lists_the_joint_sessions_it_takes_part_in(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-03', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held, 'title' => 'Running 7.2 km in 40 min',
        ]);
        $training->categories()->syncWithoutDetaching([$u17->id]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $this->player($u17)->id, 'status' => AttendanceStatus::Late, 'minutes' => 5]);

        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u17->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->has('sessions', 1)
                ->where('sessions.0.id', $training->id)
                ->where('sessions.0.title', 'Running 7.2 km in 40 min')
                ->has('sessions.0.categories', 2)
                ->where('sessions.0.categories.0.id', $u15->id)
                ->where('sessions.0.marked', 1)
                ->where('sessions.0.summary.late', 1)
                ->where('preseason.done', 1));
    }

    #[Test]
    public function a_joint_preseason_session_counts_for_every_category_in_it(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        PreseasonTarget::create(['category_id' => $u17->id, 'season_start_year' => 2026, 'target_count' => 10]);
        $base = ['category_id' => $u15->id, 'start_time' => '09:00', 'end_time' => '10:30', 'kind' => SessionKind::Preseason];

        $states = ['2026-09-02' => SessionState::Held, '2026-09-03' => SessionState::Held, '2026-09-04' => SessionState::Planned, '2026-09-05' => SessionState::Cancelled];
        foreach ($states as $date => $state) {
            TrainingSession::create($base + ['date' => $date, 'state' => $state])->categories()->syncWithoutDetaching([$u17->id]);
        }
        TrainingSession::create($base + ['date' => '2026-09-06', 'state' => SessionState::Held]); // U15 only
        TrainingSession::create($base + ['date' => '2025-09-06', 'state' => SessionState::Held]); // last season

        $progress = app(PreseasonProgress::class);

        $this->assertSame(['season' => '2026/27', 'done' => 2, 'target' => 10], $progress->forCategory($u17->id, '2026-10-01'));
        $this->assertSame(3, $progress->forCategory($u15->id, '2026-10-01')['done']);
        $this->assertNull($progress->forCategory($u15->id, '2026-10-01')['target']);
    }
}
