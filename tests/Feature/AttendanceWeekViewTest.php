<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceWeekViewTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_calendar_defaults_to_the_month_view_and_rejects_unknown_views(): void
    {
        $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')->where('view', 'month'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'year']))
            ->assertSessionHasErrors('view');
    }

    #[Test]
    public function the_week_view_generates_and_lists_every_category_for_the_week(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        TrainingSchedule::create(['category_id' => $u17->id, 'weekday' => 3, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-01-01']);
        $joint = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-31', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);

        // Wednesday 2026-10-28: the week runs Monday 26 October to Sunday 1 November.
        $this->actingAs($this->admin())->get(route('attendance.index', ['view' => 'week', 'date' => '2026-10-28']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('view', 'week')
                ->where('categoryId', null)
                ->where('week.start', '2026-10-26')
                ->where('week.end', '2026-11-01')
                ->where('month', '2026-10')
                ->has('sessions', 3)
                ->where('sessions.0.date', '2026-10-26')
                ->where('sessions.1.date', '2026-10-28')
                ->where('sessions.1.categories.0.id', $u17->id)
                ->where('sessions.2.id', $joint->id)
                ->has('sessions.2.categories', 2));

        // The week touches November, so November was generated too.
        $this->assertTrue(TrainingSession::where('category_id', $u15->id)->where('date', '2026-11-02')->exists());
    }

    #[Test]
    public function the_week_view_defaults_to_today_or_to_the_first_of_the_month_it_was_switched_from(): void
    {
        Carbon::setTestNow('2026-10-08 10:00'); // a Thursday
        $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'week']))
            ->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-10-05'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'week', 'month' => '2026-12']))
            ->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-11-30')->where('month', '2026-11'));
    }
}
