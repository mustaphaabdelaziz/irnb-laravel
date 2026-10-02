<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\AttendanceCustomStatus;
use App\Models\Category;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCustomStatusMarksTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function travelStatus(array $extra = []): AttendanceCustomStatus
    {
        return AttendanceCustomStatus::createWithKey($extra + [
            'code' => 'V', 'color' => '#7c3aed', 'label_fr' => 'Voyage', 'behaviour' => 'not_counted', 'is_active' => true,
        ]);
    }

    private function makeSession(Category $category, string $state = 'planned'): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => $state,
        ]);
    }

    #[Test]
    public function a_session_saves_a_custom_status_without_minutes_or_reason(): void
    {
        $travel = $this->travelStatus();
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->makeSession($u15);

        $this->actingAs($this->admin())->put(route('attendance.sessions.marks', $session), [
            'marks' => [
                ['player_id' => $a->id, 'status' => $travel->key, 'minutes' => 10, 'reason' => 'illness'],
                ['player_id' => $b->id, 'status' => 'present'],
            ],
        ])->assertSessionHasNoErrors();

        $mark = Attendance::where('player_id', $a->id)->first();
        $this->assertSame($travel->key, $mark->status);
        $this->assertNull($mark->minutes);
        $this->assertNull($mark->reason);
        $this->assertSame(SessionState::Held, $session->fresh()->state);
    }

    #[Test]
    public function an_unknown_status_key_is_rejected_but_a_hidden_custom_one_is_kept(): void
    {
        $hidden = $this->travelStatus(['is_active' => false]);
        $u15 = $this->category();
        $a = $this->player($u15);
        $session = $this->makeSession($u15);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $a->id, 'status' => 'c_999']],
        ])->assertSessionHasErrors('marks.0.status');

        // Hidden: no longer picked for a new mark...
        $b = $this->player($u15);
        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $a->id, 'status' => $hidden->key], ['player_id' => $b->id, 'status' => 'present']],
        ])->assertSessionHasErrors(['marks.0.status' => 'att.error.code_hidden']);

        // ...but kept where the saved mark already has it.
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $a->id, 'status' => $hidden->key]);
        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $a->id, 'status' => $hidden->key], ['player_id' => $b->id, 'status' => 'present']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $a->id, 'status' => $hidden->key], ['player_id' => $b->id, 'status' => $hidden->key]],
        ])->assertSessionHasErrors(['marks.1.status' => 'att.error.code_hidden']);
    }

    #[Test]
    public function the_grid_keeps_a_hidden_code_only_where_the_mark_already_has_it(): void
    {
        $hidden = $this->travelStatus(['is_active' => false]);
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->makeSession($u15, 'held');
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $a->id, 'status' => $hidden->key]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $b->id, 'status' => 'present']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => 'V', $b->id => 'V']]],
        ])->assertSessionHasErrors(["columns.0.codes.{$b->id}" => 'att.error.code_hidden']);

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => 'V', $b->id => '']]],
        ])->assertSessionHasNoErrors();
        $this->assertSame($hidden->key, Attendance::where('player_id', $a->id)->value('status'));
    }

    #[Test]
    public function the_grid_shows_and_parses_custom_codes(): void
    {
        $travel = $this->travelStatus();
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->makeSession($u15, 'held');
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $a->id, 'status' => $travel->key]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $b->id, 'status' => 'present']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("cells.{$a->id}.{$session->id}", 'V')
                ->where("attendanceCodes.{$travel->key}.code", 'V')
                ->where("attendanceCodes.{$travel->key}.behaviour", 'not_counted'));

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $session->id, 'codes' => [$a->id => '', $b->id => 'v']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('present', Attendance::where('player_id', $a->id)->value('status'));
        $this->assertSame($travel->key, Attendance::where('player_id', $b->id)->value('status'));
    }
}
