<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCalendarTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function opening_a_month_generates_and_lists_its_sessions_with_preseason_progress(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);
        TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-09-02', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held]);

        $this->actingAs($this->admin())
            ->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('categoryId', $u15->id)
                ->where('month', '2026-10')
                ->has('sessions', 4)
                ->where('sessions.0.date', '2026-10-05')
                ->where('preseason.done', 1)
                ->where('preseason.target', 12)
                ->where('preseason.season', '2026/27')
                ->where('hasSchedule', true));
    }

    #[Test]
    public function an_extra_session_is_created_and_a_duplicate_slot_is_rejected(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $payload = ['category_id' => $u15->id, 'kind' => 'preseason', 'date' => '2026-09-03', 'start_time' => '09:00', 'end_time' => '10:30'];

        $response = $this->actingAs($admin)->post(route('attendance.sessions.store'), $payload);
        $session = TrainingSession::sole();
        $response->assertRedirect(route('attendance.sessions.show', $session))->assertSessionHas('success', 'flash.training_session_created');
        $this->assertSame(SessionKind::Preseason, $session->kind);
        $this->assertSame(1, ActivityLog::where('action', 'training_session_created')->count());

        $this->actingAs($admin)->post(route('attendance.sessions.store'), $payload)->assertSessionHasErrors('start_time');
        $this->actingAs($admin)->post(route('attendance.sessions.store'), ['kind' => 'regular'] + $payload)->assertSessionHasErrors('kind');
    }

    #[Test]
    public function a_session_is_cancelled_with_a_reason_and_moved_keeping_its_original_date(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);

        $this->actingAs($admin)->post(route('attendance.sessions.move', $session), ['date' => '2026-10-06', 'start_time' => '17:00', 'end_time' => '18:30'])
            ->assertSessionHas('success', 'flash.training_session_moved');
        $this->actingAs($admin)->post(route('attendance.sessions.move', $session), ['date' => '2026-10-07', 'start_time' => '17:00', 'end_time' => '18:30']);
        $session->refresh();
        $this->assertSame('2026-10-07', $session->date);
        $this->assertSame('2026-10-05', $session->moved_from);

        $this->actingAs($admin)->post(route('attendance.sessions.cancel', $session), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('attendance.sessions.cancel', $session), ['reason' => 'Rain'])
            ->assertSessionHas('success', 'flash.training_session_cancelled');
        $this->assertSame(SessionState::Cancelled, $session->fresh()->state);
        $this->assertSame('Rain', $session->fresh()->cancel_reason);

        $this->actingAs($admin)->post(route('attendance.sessions.move', $session), ['date' => '2026-10-08', 'start_time' => '17:00', 'end_time' => '18:30'])
            ->assertSessionHasErrors('date');
    }

    #[Test]
    public function a_cancelled_session_cannot_be_cancelled_again(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);

        $this->actingAs($admin)->post(route('attendance.sessions.cancel', $session), ['reason' => 'Rain'])
            ->assertSessionHas('success', 'flash.training_session_cancelled');

        $this->actingAs($admin)->post(route('attendance.sessions.cancel', $session), ['reason' => 'Storm'])
            ->assertSessionHasErrors('reason');
        $this->assertSame('Rain', $session->fresh()->cancel_reason);
    }

    #[Test]
    public function a_double_submit_race_on_store_is_a_validation_error_not_a_crash(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $payload = ['category_id' => $u15->id, 'kind' => 'extra', 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30'];

        // A second submit lands the identical row right after assertSlotFree()
        // read the table clear, so it is the unique key — not that read — that
        // stops the duplicate.
        TrainingSession::creating(fn () => DB::table('training_sessions')->insert($payload + [
            'state' => SessionState::Planned->value, 'created_at' => now(), 'updated_at' => now(),
        ]));

        try {
            $this->actingAs($admin)->post(route('attendance.sessions.store'), $payload)
                ->assertSessionHasErrors('start_time');
        } finally {
            TrainingSession::flushEventListeners();
        }

        // The whole transaction (the raced insert included) rolled back with the error.
        $this->assertSame(0, TrainingSession::count());
    }

    #[Test]
    public function a_double_submit_race_on_move_is_a_validation_error_not_a_crash(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();
        $session = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);
        $target = ['category_id' => $u15->id, 'date' => '2026-10-12', 'start_time' => '17:00', 'end_time' => '18:30'];

        // A second submit moves another session into the same slot right
        // after assertSlotFree() read it clear.
        TrainingSession::updating(fn () => DB::table('training_sessions')->insert($target + [
            'kind' => SessionKind::Regular->value, 'state' => SessionState::Planned->value, 'created_at' => now(), 'updated_at' => now(),
        ]));

        try {
            $this->actingAs($admin)->post(route('attendance.sessions.move', $session), $target)
                ->assertSessionHasErrors('start_time');
        } finally {
            TrainingSession::flushEventListeners();
        }

        // The whole transaction (the raced insert included) rolled back with the error.
        $this->assertSame('2026-10-05', $session->fresh()->date);
        $this->assertSame(1, TrainingSession::count());
    }
}
