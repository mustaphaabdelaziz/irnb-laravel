<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceAgendaViewTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_agenda_lists_the_month_for_every_category_or_one(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        // October 2026: four Mondays for U15, four Wednesdays for U17.
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        TrainingSchedule::create(['category_id' => $u17->id, 'weekday' => 3, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-01-01']);
        $joint = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-03', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned, 'title' => 'Endurance',
        ]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'agenda', 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('view', 'agenda')
                ->where('categoryId', null)
                ->where('month', '2026-10')
                ->has('sessions', 9)
                ->where('sessions.0.id', $joint->id)
                ->where('sessions.0.title', 'Endurance')
                ->where('sessions.1.date', '2026-10-05')
                ->where('sessions.2.date', '2026-10-07'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'agenda', 'month' => '2026-10', 'category_id' => $u17->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $u17->id)
                ->has('sessions', 5)
                ->where('sessions.0.id', $joint->id));
    }
}
