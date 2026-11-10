<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MeetingAttendance;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMeetingAttendancePartialTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: BoardMeeting, 2: array<int, BoardMember>} */
    private function setUpMeeting(): array
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $term = BoardTerm::firstOrCreate([], ['name' => '2024–2028', 'is_current' => true]);
        $members = [];
        foreach (range(1, 7) as $i) {
            $player = Player::create(['membership_id' => "M{$i}", 'firstname' => "P{$i}", 'lastname' => 'Test']);
            $members[] = BoardMember::create(['player_id' => $player->id, 'board_term_id' => $term->id, 'name' => $player->fullname, 'role' => 'member', 'status' => 'active']);
        }
        $meeting = BoardMeeting::create(['title' => 'اجتماع', 'type' => 'ordinary', 'meeting_date' => now()->addWeek(), 'status' => 'scheduled']);

        return [$admin, $meeting, $members];
    }

    #[Test]
    public function saving_two_of_seven_members_stores_exactly_two_rows(): void
    {
        [$admin, $meeting, $members] = $this->setUpMeeting();

        $this->actingAs($admin)->put(route('board.meetings.attendance', $meeting), [
            'attendances' => [
                ['board_member_id' => $members[0]->id, 'status' => 'present'],
                ['board_member_id' => $members[3]->id, 'status' => 'excused'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, MeetingAttendance::where('board_meeting_id', $meeting->id)->count());
    }

    #[Test]
    public function an_untouched_meeting_gives_every_member_no_attendance(): void
    {
        [$admin, $meeting] = $this->setUpMeeting();

        $this->actingAs($admin)->get(route('board.meetings.show', $meeting))
            ->assertInertia(fn ($page) => $page
                ->has('members', 7)
                ->where('members.0.attendance', null)
                ->where('members.6.attendance', null));
    }
}
