<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MeetingAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMeetingCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function meeting(array $attributes = []): BoardMeeting
    {
        return BoardMeeting::create(array_merge([
            'title' => 'Monthly board',
            'type' => 'ordinary',
            'meeting_date' => now()->addWeek(),
            'status' => 'scheduled',
        ], $attributes));
    }

    #[Test]
    public function cancelling_keeps_the_meeting_with_who_when_and_why(): void
    {
        $admin = $this->admin();
        $meeting = $this->meeting();

        $this->actingAs($admin)
            ->post(route('board.meetings.cancel', $meeting), ['reason' => 'Hall unavailable'])
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.meeting_cancelled');

        $meeting->refresh();
        $this->assertSame('cancelled', $meeting->status);
        $this->assertSame('Hall unavailable', $meeting->cancel_reason);
        $this->assertSame($admin->id, $meeting->cancelled_by_user_id);
        $this->assertNotNull($meeting->cancelled_at);

        $props = $this->actingAs($admin)->get(route('board.meetings.show', $meeting))
            ->assertOk()->viewData('page')['props'];
        $this->assertSame($admin->name, $props['meeting']['cancelled_by']['name']);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->admin())
            ->post(route('board.meetings.cancel', $meeting), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('scheduled', $meeting->fresh()->status);
    }

    #[Test]
    public function a_held_meeting_cannot_be_cancelled(): void
    {
        $meeting = $this->meeting(['status' => 'held']);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.cancel', $meeting), ['reason' => 'Too late'])
            ->assertSessionHas('error', 'flash.meeting_not_cancellable');

        $this->assertSame('held', $meeting->fresh()->status);
    }

    #[Test]
    public function a_held_meeting_with_an_empty_reason_gets_the_not_cancellable_flash_not_a_validation_error(): void
    {
        $meeting = $this->meeting(['status' => 'held']);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.cancel', $meeting), ['reason' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('error', 'flash.meeting_not_cancellable');

        $this->assertSame('held', $meeting->fresh()->status);
    }

    #[Test]
    public function a_cancelled_meeting_is_read_only(): void
    {
        $admin = $this->admin();
        $meeting = $this->meeting(['status' => 'cancelled', 'cancelled_at' => now()]);
        $member = BoardMember::create(['name' => 'Karim', 'role' => 'president', 'status' => 'active']);

        $this->actingAs($admin)
            ->put(route('board.meetings.update', $meeting), [
                'title' => 'Renamed', 'type' => 'ordinary',
                'meeting_date' => now()->addWeek()->toDateTimeString(), 'status' => 'scheduled',
            ])
            ->assertSessionHas('error', 'flash.meeting_is_cancelled');

        $this->actingAs($admin)
            ->put(route('board.meetings.attendance', $meeting), [
                'attendances' => [['board_member_id' => $member->id, 'status' => 'present']],
            ])
            ->assertSessionHas('error', 'flash.meeting_is_cancelled');

        $this->assertSame('Monthly board', $meeting->fresh()->title);
        $this->assertSame(0, MeetingAttendance::count());
    }

    #[Test]
    public function cancelled_is_no_longer_a_status_the_form_can_set(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->admin())
            ->put(route('board.meetings.update', $meeting), [
                'title' => 'Monthly board', 'type' => 'ordinary',
                'meeting_date' => now()->addWeek()->toDateTimeString(), 'status' => 'cancelled',
            ])
            ->assertSessionHasErrors('status');
    }

    #[Test]
    public function meetings_can_no_longer_be_deleted(): void
    {
        $meeting = $this->meeting();

        $this->assertFalse(Route::has('board.meetings.destroy'));
        $this->actingAs($this->admin())
            ->delete('/board/meetings/'.$meeting->id)
            ->assertStatus(405);

        $this->assertNotNull($meeting->fresh());
    }

    #[Test]
    public function the_board_overview_leaves_cancelled_meetings_out_of_the_term_total(): void
    {
        BoardTerm::create(['name' => 'T', 'start_date' => '2026-01-01', 'end_date' => '2029-12-31', 'is_current' => true]);
        $this->meeting(['meeting_date' => '2026-06-01 18:00:00', 'status' => 'held']);
        $this->meeting(['meeting_date' => '2026-07-01 18:00:00', 'status' => 'cancelled']);

        $props = $this->actingAs($this->admin())->get(route('board.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['stats']['meetings_total']);
    }

    #[Test]
    public function the_meetings_list_filters_by_status(): void
    {
        $this->meeting();
        $this->meeting(['status' => 'cancelled']);

        $props = $this->actingAs($this->admin())->get(route('board.meetings', ['status' => 'cancelled']))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['meetings']);
        $this->assertSame('cancelled', $props['filters']['status']);
    }
}
