<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceTimelineViewTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_timeline_merges_sessions_closures_and_preseason_milestones_in_order(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 2]);
        $base = ['category_id' => $u15->id, 'start_time' => '09:00', 'end_time' => '10:30', 'kind' => SessionKind::Preseason, 'state' => SessionState::Held];

        $first = TrainingSession::create($base + ['date' => '2026-09-02', 'title' => 'Endurance']);
        $first->categories()->syncWithoutDetaching([$u17->id]);
        Attendance::create(['training_session_id' => $first->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);
        $second = TrainingSession::create($base + ['date' => '2026-09-04']);
        TrainingSession::create(['date' => '2026-09-10', 'kind' => SessionKind::Extra, 'state' => SessionState::Cancelled, 'cancel_reason' => 'Rain'] + $base);
        ClubClosure::create(['start_date' => '2026-08-25', 'end_date' => '2026-09-01', 'reason' => 'Summer']);

        $this->actingAs($this->admin())->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('view', 'timeline')
                ->where('from', '2026-09')
                ->where('to', '2026-09')
                ->where('month', '2026-09')
                ->has('events', 7)
                ->where('events.0.type', 'closure')
                ->where('events.0.date', '2026-09-01')
                ->where('events.0.reason', 'Summer')
                ->where('events.1.type', 'session')
                ->where('events.1.id', $first->id)
                ->where('events.1.title', 'Endurance')
                ->where('events.1.summary.present', 1)
                ->where('events.2.type', 'milestone')
                ->where('events.2.milestone', 'started')
                ->where('events.2.category_id', $u15->id)
                ->where('events.3.milestone', 'started')
                ->where('events.3.category_id', $u17->id)
                ->where('events.4.id', $second->id)
                ->where('events.5.milestone', 'completed')
                ->where('events.5.done', 2)
                ->where('events.5.target', 2)
                ->where('events.6.state', 'cancelled')
                ->where('events.6.cancel_reason', 'Rain'));
    }

    #[Test]
    public function the_timeline_filters_by_category_and_kind(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $base = ['date' => '2026-09-02', 'start_time' => '09:00', 'end_time' => '10:30'];
        TrainingSession::create($base + ['category_id' => $u15->id, 'kind' => SessionKind::Preseason, 'state' => SessionState::Held]);
        $extra = TrainingSession::create($base + ['category_id' => $u17->id, 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09', 'category_id' => $u17->id]))
            ->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.id', $extra->id));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09', 'kind' => 'extra']))
            ->assertInertia(fn (Assert $page) => $page->where('kind', 'extra')->has('events', 1)->where('events.0.id', $extra->id));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->has('events', 3)); // 2 sessions + U15 "started"
    }

    #[Test]
    public function the_timeline_window_is_ordered_and_capped_at_twelve_months(): void
    {
        $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'from' => '2025-01', 'to' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('from', '2025-11')->where('to', '2026-10'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'from' => '2026-10', 'to' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->where('from', '2026-09')->where('to', '2026-10'));
    }
}
