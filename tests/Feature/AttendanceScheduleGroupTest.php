<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceScheduleGroupTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'weekday' => 1,
            'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01',
        ];
    }

    #[Test]
    public function a_line_stores_its_group_and_the_page_lists_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->payload(['group_id' => $this->g1->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->g1->id, TrainingSchedule::sole()->group_id);
        $this->actingAs($admin)->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page->where('schedules.0.group_id', $this->g1->id));
    }

    #[Test]
    public function a_group_of_another_category_or_branch_is_refused(): void
    {
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $other = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $cadet->id, 'name' => 'C1']);
        $foot = Branch::create(['name' => 'Foot']);
        $unowned = Category::create(['name' => 'Open']);
        $unowned->update(['branch_id' => null]);
        $footGroup = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $unowned->id, 'name' => 'F1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->payload(['group_id' => $other->id]))
            ->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->payload(['category_id' => $unowned->id, 'group_id' => $footGroup->id]))
            ->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->assertSame(0, TrainingSchedule::count());
    }

    #[Test]
    public function the_generator_copies_the_line_group(): void
    {
        TrainingSchedule::create($this->payload(['group_id' => $this->g1->id]));
        TrainingSchedule::create($this->payload(['weekday' => 3]));

        // October 2026: Mondays 5, 12, 19, 26; Wednesdays 7, 14, 21, 28.
        app(SessionGenerator::class)->forRange('2026-10-01', '2026-10-31');

        $this->assertSame(4, TrainingSession::where('group_id', $this->g1->id)->count());
        $this->assertSame(4, TrainingSession::whereNull('group_id')->count());
        $this->assertSame(['2026-10-05'], TrainingSession::where('group_id', $this->g1->id)->orderBy('date')->limit(1)->pluck('date')->all());
    }

    #[Test]
    public function giving_a_line_a_group_retags_unsaved_sessions_and_leaves_saved_ones(): void
    {
        Carbon::setTestNow('2026-10-10');
        $admin = $this->admin();
        $schedule = TrainingSchedule::create($this->payload());
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        $base = [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'schedule_id' => $schedule->id,
            'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ];
        $saved = TrainingSession::create($base + ['date' => '2026-09-28']);
        app(MarkRecorder::class)->save($saved, [['player_id' => $player->id, 'status' => 'present']], $admin);
        $past = TrainingSession::create($base + ['date' => '2026-10-05']);
        $future = TrainingSession::create($base + ['date' => '2026-10-12']);
        $moved = TrainingSession::create($base + ['date' => '2026-10-20', 'moved_from' => '2026-10-19']);

        $this->actingAs($admin)->put(route('attendance.schedules.update', $schedule), $this->payload(['group_id' => $this->g1->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull($saved->fresh()->group_id);
        $this->assertSame($this->g1->id, $past->fresh()->group_id);
        $this->assertSame($this->g1->id, $moved->fresh()->group_id);
        $this->assertModelMissing($future); // regenerated with the group on the next month view

        app(SessionGenerator::class)->forRange('2026-10-01', '2026-10-31');
        $this->assertSame($this->g1->id, TrainingSession::where('date', '2026-10-12')->sole()->group_id);
    }

    #[Test]
    public function clearing_the_group_of_a_line_brings_unsaved_sessions_back_to_the_whole_category(): void
    {
        Carbon::setTestNow('2026-10-10');
        $schedule = TrainingSchedule::create($this->payload(['group_id' => $this->g1->id]));
        $past = TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'schedule_id' => $schedule->id, 'group_id' => $this->g1->id,
            'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->put(route('attendance.schedules.update', $schedule), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNull($schedule->fresh()->group_id);
        $this->assertNull($past->fresh()->group_id);
    }
}
