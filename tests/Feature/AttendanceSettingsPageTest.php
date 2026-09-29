<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\Role;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSettingsPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_page_lists_everything(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Settings')
                ->has('schedules', 1)->has('categories', 1)->has('seasons', 2)
                ->where('settings.points.present', 1));
    }

    #[Test]
    public function schedules_are_validated_created_and_edits_regenerate_future_planned_sessions(): void
    {
        Carbon::setTestNow('2026-10-10');
        $u15 = $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), [
            'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '19:00', 'end_time' => '18:00', 'valid_from' => '2026-09-01',
        ])->assertSessionHasErrors('end_time');

        $this->actingAs($admin)->post(route('attendance.schedules.store'), [
            'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01',
        ])->assertSessionHas('success', 'flash.training_schedule_saved');
        $schedule = TrainingSchedule::sole();

        $base = ['category_id' => $u15->id, 'schedule_id' => $schedule->id, 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular];
        $past = TrainingSession::create($base + ['date' => '2026-10-05', 'state' => SessionState::Planned]);
        $future = TrainingSession::create($base + ['date' => '2026-10-12', 'state' => SessionState::Planned]);
        $held = TrainingSession::create($base + ['date' => '2026-10-19', 'state' => SessionState::Held]);

        $this->actingAs($admin)->put(route('attendance.schedules.update', $schedule), [
            'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('17:00', $schedule->fresh()->start_time);
        $this->assertModelExists($past);
        $this->assertModelMissing($future);
        $this->assertModelExists($held);
    }

    #[Test]
    public function a_closure_removes_unmarked_planned_sessions_inside_it(): void
    {
        $u15 = $this->category();
        $base = ['category_id' => $u15->id, 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned];
        $inside = TrainingSession::create($base + ['date' => '2026-12-22']);
        $outside = TrainingSession::create($base + ['date' => '2027-01-05']);

        $this->actingAs($this->admin())->post(route('attendance.closures.store'), [
            'start_date' => '2026-12-20', 'end_date' => '2027-01-03', 'reason' => 'Winter break',
        ])->assertSessionHas('success', 'flash.club_closure_saved');

        $this->assertModelMissing($inside);
        $this->assertModelExists($outside);

        $this->actingAs($this->admin())->delete(route('attendance.closures.destroy', ClubClosure::sole()))
            ->assertSessionHas('success', 'flash.club_closure_deleted');
        $this->assertSame(0, ClubClosure::count());
    }

    #[Test]
    public function preseason_target_and_scoring_settings_are_saved(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.preseason-targets.store'), ['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);
        $this->actingAs($admin)->post(route('attendance.preseason-targets.store'), ['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 14])
            ->assertSessionHas('success', 'flash.preseason_target_saved');
        $this->assertSame(14, PreseasonTarget::sole()->target_count);

        $payload = AttendanceSettings::DEFAULTS;
        $payload['points']['late'] = 0.5;
        $payload['alerts']['min_score_pct'] = 75;
        $this->actingAs($admin)->put(route('attendance.settings.update'), $payload)
            ->assertSessionHas('success', 'flash.attendance_settings_saved');
        $this->assertEquals(0.5, AttendanceSettings::get()['points']['late']);
        $this->assertSame(75, AttendanceSettings::get()['alerts']['min_score_pct']);
    }

    #[Test]
    public function view_only_users_cannot_open_settings(): void
    {
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view']]]);
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('attendance.settings'))->assertForbidden();
    }
}
