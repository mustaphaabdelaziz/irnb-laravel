<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\BoardMeeting;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_actor_action_subject_and_small_properties(): void
    {
        $user = User::factory()->create();
        $meeting = BoardMeeting::create(['title' => 'AG', 'type' => 'general_assembly', 'meeting_date' => now(), 'status' => 'scheduled']);

        $log = ActivityRecorder::record($user, ActivityAction::MEETING_CREATED, $meeting, ['count' => 3, 'skip' => null]);

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('meeting_created', $log->action);
        $this->assertSame($meeting->getMorphClass(), $log->subject_type);
        $this->assertSame($meeting->id, $log->subject_id);
        $this->assertSame(['count' => 3], $log->fresh()->properties);
        $this->assertNotNull($log->occurred_at);
        $this->assertTrue($log->fresh()->subject->is($meeting));
    }

    #[Test]
    public function subject_and_properties_are_optional_and_empty_properties_store_null(): void
    {
        $log = ActivityRecorder::record(User::factory()->create(), ActivityAction::PLAYER_IMPORTED, null, []);

        $this->assertNull($log->subject_type);
        $this->assertNull($log->fresh()->properties);
    }

    #[Test]
    public function an_unknown_action_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActivityRecorder::record(User::factory()->create(), 'made_up');
    }

    #[Test]
    public function every_code_belongs_to_exactly_one_area(): void
    {
        $inAreas = array_merge(...array_values(ActivityAction::AREAS));
        sort($inAreas);
        $all = ActivityAction::ALL;
        sort($all);

        $this->assertSame($all, $inAreas);
        $this->assertCount(35, ActivityAction::ALL);
        $this->assertSame(count($inAreas), count(array_unique($inAreas)));
    }

    #[Test]
    public function the_table_has_no_updated_at_and_rows_survive_user_deletion(): void
    {
        $user = User::factory()->create();
        ActivityRecorder::record($user, ActivityAction::JOB_CREATED);
        $user->delete();

        $this->assertNull(ActivityLog::query()->sole()->user_id);
        $this->assertFalse(\Schema::hasColumn('activity_logs', 'updated_at'));
    }
}
