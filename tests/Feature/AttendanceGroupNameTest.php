<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\CalendarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupNameTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_calendar_feed_and_the_session_page_name_the_group(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $base = [
            'branch_id' => $swim->id, 'category_id' => $minime->id, 'date' => '2026-11-21', 'end_time' => '23:00',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ];
        $grouped = TrainingSession::create($base + ['group_id' => $group->id, 'start_time' => '09:30']);
        $plain = TrainingSession::create($base + ['start_time' => '11:00']);

        $feed = app(CalendarFeed::class)->sessions('2026-11-01', '2026-11-30');
        $this->assertSame(['G1', null], $feed->pluck('group')->all());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('attendance.sessions.show', $grouped))
            ->assertInertia(fn (Assert $page) => $page->where('session.group', 'G1'));
        $this->actingAs($admin)->get(route('attendance.sessions.show', $plain))
            ->assertInertia(fn (Assert $page) => $page->where('session.group', null));
    }
}
