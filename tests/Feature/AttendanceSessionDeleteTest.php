<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionDeleteTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** October 2026 Mondays from a weekly schedule: 5, 12, 19, 26. */
    private function generateMondays(Category $category): void
    {
        TrainingSchedule::create([
            'category_id' => $category->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ]);
        app(SessionGenerator::class)->forMonth($category->id, 2026, 10);
    }

    private function markAll(TrainingSession $session, array $playerIds): void
    {
        $this->actingAs($this->admin())->put(route('attendance.sessions.marks', $session), [
            'marks' => array_map(fn (int $id) => ['player_id' => $id, 'status' => 'present'], $playerIds),
        ])->assertSessionHasNoErrors();
    }

    private function dates(Category $category): array
    {
        return TrainingSession::where('category_id', $category->id)->orderBy('date')->pluck('date')->all();
    }

    #[Test]
    public function deleting_a_held_regular_session_erases_its_marks_and_the_schedule_never_recreates_it(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $this->generateMondays($u15);
        $session = TrainingSession::where('date', '2026-10-12')->first();
        $this->markAll($session, [$a->id, $b->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('attendance.sessions.destroy', $session))
            ->assertRedirect(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertSessionHas('success', 'flash.training_session_deleted');

        $this->assertNull(TrainingSession::find($session->id));
        $this->assertSame(0, Attendance::where('training_session_id', $session->id)->count());
        $this->assertSame(0, DB::table('training_session_category')->where('training_session_id', $session->id)->count());
        $log = ActivityLog::where('action', ActivityAction::TRAINING_SESSION_DELETED)->sole();
        $this->assertSame(2, $log->properties['marks']);
        $this->assertSame($admin->id, $log->user_id);

        // Opening the calendar again generates the month again, without that Monday.
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))->assertOk();
        $this->assertSame(['2026-10-05', '2026-10-19', '2026-10-26'], $this->dates($u15));
    }

    #[Test]
    public function deleting_a_moved_regular_session_keeps_its_original_slot_free_too(): void
    {
        $u15 = $this->category();
        $this->generateMondays($u15);
        $session = TrainingSession::where('date', '2026-10-12')->first();
        $this->actingAs($this->admin())->post(route('attendance.sessions.move', $session), [
            'date' => '2026-10-14', 'start_time' => '17:00', 'end_time' => '18:30',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->delete(route('attendance.sessions.destroy', $session->fresh()))->assertRedirect();

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);
        $this->assertSame(['2026-10-05', '2026-10-19', '2026-10-26'], $this->dates($u15));
    }

    #[Test]
    public function deleting_an_extra_session_leaves_no_deleted_slot(): void
    {
        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-10', 'start_time' => '10:00', 'end_time' => '11:00',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->delete(route('attendance.sessions.destroy', $session))->assertRedirect();

        $this->assertNull(TrainingSession::find($session->id));
        $this->assertSame(0, DB::table('deleted_session_slots')->count());
    }

    #[Test]
    public function a_cancelled_session_can_be_deleted(): void
    {
        $u15 = $this->category();
        $this->generateMondays($u15);
        $session = TrainingSession::where('date', '2026-10-05')->first();
        $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $this->actingAs($this->admin())->delete(route('attendance.sessions.destroy', $session))->assertRedirect();

        $this->assertNull(TrainingSession::find($session->id));
    }

    #[Test]
    public function erasing_marks_keeps_the_session_planned_and_empty(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $this->generateMondays($u15);
        $session = TrainingSession::where('date', '2026-10-12')->first();
        $this->markAll($session, [$a->id]);
        $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $this->actingAs($this->admin())->post(route('attendance.sessions.reset', $session))
            ->assertSessionHas('success', 'flash.training_session_reset');

        $session->refresh();
        $this->assertSame(SessionState::Planned, $session->state);
        $this->assertNull($session->cancel_reason);
        $this->assertSame(0, $session->attendances()->count());
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::TRAINING_SESSION_RESET)->sole()->properties['marks']);
    }

    #[Test]
    public function the_session_page_sends_the_number_of_saved_marks(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $this->generateMondays($u15);
        $session = TrainingSession::where('date', '2026-10-12')->first();
        $this->markAll($session, [$a->id, $b->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page->where('marksCount', 2));
    }

    #[Test]
    public function a_viewer_can_neither_delete_nor_erase(): void
    {
        $u15 = $this->category();
        $this->generateMondays($u15);
        $session = TrainingSession::where('date', '2026-10-12')->first();
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['attendance' => ['view']]])->id]);

        $this->actingAs($viewer)->delete(route('attendance.sessions.destroy', $session))->assertForbidden();
        $this->actingAs($viewer)->post(route('attendance.sessions.reset', $session))->assertForbidden();
        $this->assertNotNull(TrainingSession::find($session->id));
    }
}
