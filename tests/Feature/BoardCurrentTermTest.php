<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTask;
use App\Models\BoardTerm;
use App\Models\MeetingAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardCurrentTermTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BoardMember $old;

    private BoardMeeting $held;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        BoardTerm::query()->update(['is_current' => false]);
        $oldTerm = BoardTerm::create(['name' => 'old', 'is_current' => false]);
        $current = BoardTerm::create(['name' => 'new', 'is_current' => true]);

        $make = fn ($term, $name, $role, $status, $order) => BoardMember::create([
            'board_term_id' => $term->id, 'name' => $name, 'role' => $role, 'status' => $status, 'sort_order' => $order,
        ]);
        $this->old = $make($oldTerm, 'قديم', 'president', 'active', 0);
        $make($current, 'أ', 'president', 'active', 1);
        $make($current, 'ب', 'member', 'active', 2);
        $make($current, 'سابق', 'member', 'former', 3);

        $this->held = BoardMeeting::create(['title' => 'منعقد', 'type' => 'ordinary', 'meeting_date' => now()->subWeek(), 'status' => 'held']);
        MeetingAttendance::create(['board_meeting_id' => $this->held->id, 'board_member_id' => $this->old->id, 'status' => 'present']);
    }

    private function names(string $url, string $prop): array
    {
        $names = [];
        $this->actingAs($this->admin)->get($url)->assertInertia(function ($page) use ($prop, &$names) {
            $names = collect($page->toArray()['props'][$prop])->pluck('name')->all();
        });

        return $names;
    }

    #[Test]
    public function overview_counts_only_the_current_term(): void
    {
        $this->actingAs($this->admin)->get(route('board.index'))
            ->assertInertia(fn ($page) => $page->where('stats.members', 2));
        $this->assertSame(['أ', 'ب'], $this->names(route('board.index'), 'membersByRole'));
    }

    #[Test]
    public function a_new_meeting_lists_current_members_and_a_held_one_keeps_those_recorded(): void
    {
        $new = BoardMeeting::create(['title' => 'جديد', 'type' => 'ordinary', 'meeting_date' => now()->addWeek(), 'status' => 'scheduled']);

        $url = route('board.meetings.show', $new);
        $this->assertSame(['أ', 'ب'], $this->names($url, 'members'));
        $this->assertSame(['أ', 'ب'], $this->names($url, 'allMembers'));

        $url = route('board.meetings.show', $this->held);
        $this->assertSame(['قديم', 'أ', 'ب'], $this->names($url, 'members'));
        $this->actingAs($this->admin)->get($url)->assertInertia(fn ($page) => $page->where('members.0.attendance', 'present'));
    }

    #[Test]
    public function task_assignees_are_the_current_term_plus_people_already_assigned(): void
    {
        $this->assertSame(['أ', 'ب'], $this->names(route('board.tasks'), 'members'));

        BoardTask::create(['title' => 'مهمة', 'board_member_id' => $this->old->id, 'status' => 'not_started']);

        $this->assertSame(['قديم', 'أ', 'ب'], $this->names(route('board.tasks'), 'members'));
    }

    #[Test]
    public function the_current_term_is_the_flagged_one_else_the_newest(): void
    {
        $this->assertSame('new', BoardTerm::current()->name);
        BoardTerm::query()->update(['is_current' => false]);
        $this->assertSame('new', BoardTerm::current()->name);
        $this->assertSame(2, BoardMember::inCurrentTerm()->where('status', 'active')->count());
    }
}
