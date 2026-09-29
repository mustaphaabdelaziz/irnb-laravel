<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGridTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_grid_lists_non_cancelled_sessions_with_roster_cells(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']));
        $sessions = TrainingSession::orderBy('date')->get();
        $sessions[0]->update(['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);
        Attendance::create(['training_session_id' => $sessions[1]->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Late, 'minutes' => 15]);
        $sessions[1]->update(['state' => SessionState::Held]);

        $this->actingAs($this->admin())->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Grid')
                ->has('sessions', 3)
                ->has('rows', 1)
                ->where("cells.{$a->id}.{$sessions[1]->id}", 'R15')
                ->where("cells.{$a->id}.{$sessions[2]->id}", ''));
    }

    #[Test]
    public function saving_parses_codes_keeps_known_reasons_and_rejects_bad_codes(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'held']);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Present]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $b->id, 'status' => AttendanceStatus::AbsentExcused, 'reason' => AbsenceReason::Injury]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => 'X', $b->id => 'AE']]],
        ])->assertSessionHasErrors("columns.0.codes.{$a->id}");

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => 'r20', $b->id => 'AE']]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_saved');

        $marks = $session->attendances()->get()->keyBy('player_id');
        $this->assertSame(AttendanceStatus::Late, $marks[$a->id]->status);
        $this->assertSame(20, $marks[$a->id]->minutes);
        $this->assertSame(AbsenceReason::Injury, $marks[$b->id]->reason);
    }

    #[Test]
    public function an_empty_cell_saves_as_present_and_new_excuses_default_to_other(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'planned']);

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => '', $b->id => 'ae']]],
        ])->assertSessionHasNoErrors();

        $marks = $session->attendances()->get()->keyBy('player_id');
        $this->assertSame(AttendanceStatus::Present, $marks[$a->id]->status);
        $this->assertSame(AbsenceReason::Other, $marks[$b->id]->reason);
        $this->assertSame(SessionState::Held, $session->fresh()->state);
    }

    #[Test]
    public function a_bad_cell_in_one_column_saves_no_column(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $first = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'planned']);
        $second = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-07', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'planned']);

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [
                ['session_id' => $first->id, 'codes' => [$a->id => 'P', $b->id => 'P']],
                ['session_id' => $second->id, 'codes' => [$a->id => 'X', $b->id => 'P']],
            ],
        ])->assertSessionHasErrors("columns.1.codes.{$a->id}");

        $this->assertSame(0, Attendance::count());
        $this->assertSame(SessionState::Planned, $first->fresh()->state);
        $this->assertSame(SessionState::Planned, $second->fresh()->state);
    }

    #[Test]
    public function a_player_outside_the_roster_is_rejected(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $x = $this->player($u17);
        $training = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'planned']);

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'P', $x->id => 'P']]],
        ])->assertSessionHasErrors("columns.0.codes.{$x->id}");

        $this->assertSame(0, Attendance::count());

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => ["{$a->id}abc" => 'P']]],
        ])->assertSessionHasErrors("columns.0.codes.{$a->id}abc");

        $this->assertSame(0, Attendance::count());
    }

    #[Test]
    public function a_cancelled_column_is_rejected(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $training = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => 'regular', 'state' => 'cancelled']);

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'P']]],
        ])->assertSessionHasErrors('columns.0');

        $this->assertSame(0, Attendance::count());
    }
}
