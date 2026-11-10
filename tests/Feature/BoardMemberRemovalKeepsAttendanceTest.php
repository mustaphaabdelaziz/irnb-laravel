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

class BoardMemberRemovalKeepsAttendanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function removing_a_member_keeps_their_attendance_under_their_name(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $term = BoardTerm::firstOrCreate([], ['name' => '2024–2028', 'is_current' => true]);
        $player = Player::create(['membership_id' => 'M1', 'firstname' => 'Sami', 'lastname' => 'Zidane']);
        $member = BoardMember::create(['player_id' => $player->id, 'board_term_id' => $term->id, 'name' => $player->fullname, 'role' => 'treasurer', 'status' => 'active']);
        $meeting = BoardMeeting::create(['title' => 'اجتماع', 'type' => 'ordinary', 'meeting_date' => now()->subWeek(), 'status' => 'held']);

        $this->actingAs($admin)->put(route('board.meetings.attendance', $meeting), [
            'attendances' => [['board_member_id' => $member->id, 'status' => 'present']],
        ]);
        $this->actingAs($admin)->delete(route('board.members.destroy', $member));

        $row = MeetingAttendance::where('board_meeting_id', $meeting->id)->sole();
        $this->assertNull($row->board_member_id);
        $this->assertSame($player->fullname, $row->member_name);
        $this->assertSame('treasurer', $row->member_role);
        $this->assertSame('present', $row->status);

        $this->actingAs($admin)->get(route('board.meetings.show', $meeting))
            ->assertInertia(fn ($page) => $page
                ->where('removedAttendances.0.name', $player->fullname)
                ->where('removedAttendances.0.status', 'present'));

        $this->actingAs($admin)->get(route('board.meetings.minutes', $meeting))->assertOk();
    }

    #[Test]
    public function two_removed_members_on_one_meeting_are_both_kept(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $term = BoardTerm::firstOrCreate([], ['name' => '2024–2028', 'is_current' => true]);
        $meeting = BoardMeeting::create(['title' => 'اجتماع', 'type' => 'ordinary', 'meeting_date' => now()->subWeek(), 'status' => 'held']);
        $members = [];
        $names = [];
        foreach ([['M1', 'Sami', 'Zidane', 'treasurer'], ['M2', 'Omar', 'Benali', 'secretary']] as [$id, $first, $last, $role]) {
            $player = Player::create(['membership_id' => $id, 'firstname' => $first, 'lastname' => $last]);
            $names[] = $player->fullname;
            $members[] = BoardMember::create(['player_id' => $player->id, 'board_term_id' => $term->id, 'name' => $player->fullname, 'role' => $role, 'status' => 'active']);
        }

        $this->actingAs($admin)->put(route('board.meetings.attendance', $meeting), [
            'attendances' => [
                ['board_member_id' => $members[0]->id, 'status' => 'present'],
                ['board_member_id' => $members[1]->id, 'status' => 'absent'],
            ],
        ]);
        foreach ($members as $m) {
            $this->actingAs($admin)->delete(route('board.members.destroy', $m));
        }

        $rows = MeetingAttendance::where('board_meeting_id', $meeting->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows->whereNull('board_member_id')->count());
        $this->assertEqualsCanonicalizing(
            $names,
            $rows->pluck('member_name')->all()
        );
    }
}
