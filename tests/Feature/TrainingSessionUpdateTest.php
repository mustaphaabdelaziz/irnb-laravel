<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Role;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Attendance\GenerationMarks;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingSessionUpdateTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function makeTraining(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
    }

    private function mark(TrainingSession $training, Player $player): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $player->category_id,
            'status' => AttendanceStatus::Present->value,
        ]);
        $training->update(['state' => SessionState::Held]);
    }

    private function edit(TrainingSession $training, array $data)
    {
        return $this->actingAs($this->admin())->put(route('attendance.sessions.update', $training), $data);
    }

    #[Test]
    public function details_can_always_be_edited_even_after_marks(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $this->mark($training, $this->player($u15));

        $this->edit($training, [
            'title' => 'Finishing', 'coach' => 'Karim', 'notes' => 'Wet pitch',
            'date' => '2026-10-06', 'start_time' => '17:00', 'end_time' => '18:30',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_updated');

        $fresh = $training->fresh();
        $this->assertSame(['Finishing', 'Karim', 'Wet pitch', '2026-10-06', '17:00', '18:30', '2026-10-05'],
            [$fresh->title, $fresh->coach, $fresh->notes, $fresh->date, $fresh->start_time, $fresh->end_time, $fresh->moved_from]);
        $this->assertSame(['coach', 'date', 'end_time', 'moved_from', 'notes', 'start_time', 'title'],
            collect(ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->sole()->properties['fields'])->sort()->values()->all());
    }

    #[Test]
    public function moving_twice_keeps_the_first_original_date_and_a_title_edit_moves_nothing(): void
    {
        $training = $this->makeTraining($this->category());
        $this->edit($training, ['date' => '2026-10-06', 'start_time' => '18:00', 'end_time' => '19:30'])->assertSessionHasNoErrors();
        $this->edit($training, ['date' => '2026-10-07', 'start_time' => '18:00', 'end_time' => '19:30'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-05', $training->fresh()->moved_from);

        $other = $this->makeTraining($this->category('U17'));
        $this->edit($other, ['title' => 'Only a title'])->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->moved_from);
    }

    #[Test]
    public function a_cancelled_session_cannot_be_edited(): void
    {
        $training = $this->makeTraining($this->category(), ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $this->edit($training, ['title' => 'x'])->assertSessionHasErrors(['date' => 'att.error.cancelled']);
    }

    #[Test]
    public function end_must_follow_start(): void
    {
        $training = $this->makeTraining($this->category());

        $this->edit($training, ['date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '17:00'])->assertSessionHasErrors('end_time');
    }

    #[Test]
    public function roster_fields_lock_once_marks_are_saved_but_unchanged_values_pass(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $registered = (int) PlayerStatus::where('code', 'registered')->value('id');
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id, 'roster_status_ids' => [$registered]]);
        $this->mark($training, $this->player($u15));

        $this->edit($training, ['branch_id' => $swim->id])->assertSessionHasErrors(['branch_id' => 'att.error.already_marked']);
        $this->edit($training, ['kind' => 'preseason'])->assertSessionHasErrors(['kind' => 'att.error.already_marked']);
        $paused = (int) PlayerStatus::where('code', 'paused')->value('id');
        $this->edit($training, ['roster_status_ids' => [$paused]])->assertSessionHasErrors(['roster_status_ids' => 'att.error.already_marked']);

        $this->edit($training, ['branch_id' => $foot->id, 'kind' => 'extra', 'roster_status_ids' => [$registered], 'title' => 'Same'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Same', $training->fresh()->title);
    }

    #[Test]
    public function a_regular_session_keeps_its_branch_and_kind(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id, 'kind' => SessionKind::Regular]);

        $this->edit($training, ['branch_id' => $swim->id])->assertSessionHasErrors(['branch_id' => 'att.error.not_hand_added']);
        $this->edit($training, ['kind' => 'extra'])->assertSessionHasErrors(['kind' => 'att.error.not_hand_added']);
        $this->edit($training, ['category_ids' => [$u15->id, $this->category('U17')->id]])->assertSessionHasErrors(['category_ids' => 'att.error.not_preseason']);
    }

    #[Test]
    public function an_extra_session_becomes_pre_season_with_categories_and_back(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $training = $this->makeTraining($u15);

        $this->edit($training, ['kind' => 'preseason', 'category_ids' => [$u15->id, $u17->id]])->assertSessionHasNoErrors();
        $this->assertSame(SessionKind::Preseason, $training->fresh()->kind);
        $this->assertSame([$u15->id, $u17->id], $training->fresh()->categoryIds());

        $this->edit($training, ['kind' => 'extra'])->assertSessionHasNoErrors();
        $this->assertSame(SessionKind::Extra, $training->fresh()->kind);
        $this->assertSame([$u15->id], $training->fresh()->categoryIds());
    }

    #[Test]
    public function dropping_the_primary_category_makes_the_first_chosen_one_primary(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $u19 = $this->category('U19');
        $training = $this->makeTraining($u15, ['kind' => SessionKind::Preseason]);

        $this->edit($training, ['category_ids' => [$u17->id, $u19->id]])->assertSessionHasNoErrors();

        $this->assertSame($u17->id, $training->fresh()->category_id);
        $this->assertSame([$u17->id, $u19->id], $training->fresh()->categoryIds());
    }

    #[Test]
    public function the_slot_check_uses_the_resulting_branch_and_time(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->makeTraining($u15, ['branch_id' => $foot->id]);
        $swimming = $this->makeTraining($u15, ['branch_id' => $swim->id]);

        $this->edit($swimming, ['branch_id' => $foot->id])->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);
        $this->edit($swimming, ['branch_id' => $foot->id, 'date' => '2026-10-05', 'start_time' => '20:00', 'end_time' => '21:00'])->assertSessionHasNoErrors();
        $this->assertSame($foot->id, $swimming->fresh()->branch_id);
    }

    #[Test]
    public function changing_the_branch_changes_the_expected_roster(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $footballer = $this->player($u15);
        $swimmer = $this->player($u15);
        $foot->players()->attach($footballer->id);
        $swim->players()->attach($swimmer->id);
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id]);

        $this->edit($training, ['branch_id' => $swim->id])->assertSessionHasNoErrors();

        $this->assertSame([$swimmer->id], app(Roster::class)->forSession($training->fresh())->pluck('id')->all());
    }

    #[Test]
    public function editing_needs_attendance_edit(): void
    {
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.sessions.update'));

        $training = $this->makeTraining($this->category());
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['attendance' => ['view']]])->id]);
        $this->actingAs($viewer)->put(route('attendance.sessions.update', $training), ['title' => 'x'])->assertForbidden();
    }

    #[Test]
    public function a_title_edit_leaves_an_unsorted_stored_roster_untouched(): void
    {
        $u15 = $this->category();
        $registered = (int) PlayerStatus::where('code', 'registered')->value('id');
        $paused = (int) PlayerStatus::where('code', 'paused')->value('id');
        $training = $this->makeTraining($u15, ['roster_status_ids' => [max($registered, $paused), min($registered, $paused)]]);
        $stored = $training->fresh()->roster_status_ids;

        $this->edit($training, ['title' => 'Only title'])->assertSessionHasNoErrors();

        $this->assertSame(['title'], ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->sole()->properties['fields']);
        $this->assertSame($stored, $training->fresh()->roster_status_ids);
    }

    #[Test]
    public function sending_back_the_effective_default_set_never_pins_it_on_a_title_edit(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $default = app(Roster::class)->statusIdsFor($training);

        $this->edit($training, ['title' => 'Only title', 'roster_status_ids' => $default])->assertSessionHasNoErrors();

        $this->assertNull($training->fresh()->roster_status_ids);
        $this->assertSame(['title'], ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->sole()->properties['fields']);
    }

    #[Test]
    public function a_marked_session_accepts_the_effective_default_set_back(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $this->mark($training, $this->player($u15));

        $this->edit($training, ['title' => 'Marked', 'roster_status_ids' => app(Roster::class)->statusIdsFor($training)])->assertSessionHasNoErrors();

        $this->assertNull($training->fresh()->roster_status_ids);
    }

    #[Test]
    public function an_unsaved_session_stores_a_set_that_differs_from_the_default(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $registered = (int) PlayerStatus::where('code', 'registered')->value('id');
        $paused = (int) PlayerStatus::where('code', 'paused')->value('id');
        $this->assertSame([$registered], app(Roster::class)->statusIdsFor($training));

        $this->edit($training, ['roster_status_ids' => [max($registered, $paused), min($registered, $paused)]])->assertSessionHasNoErrors();

        $this->assertSame([min($registered, $paused), max($registered, $paused)], $training->fresh()->roster_status_ids);
    }

    #[Test]
    public function an_edit_that_changes_nothing_records_no_activity(): void
    {
        $training = $this->makeTraining($this->category(), ['title' => 'Same']);

        $this->edit($training, ['title' => 'Same'])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_updated');

        $this->assertSame(0, ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->count());
    }

    #[Test]
    public function a_hand_added_session_cannot_become_regular(): void
    {
        $training = $this->makeTraining($this->category());

        $this->edit($training, ['kind' => 'regular'])->assertSessionHasErrors(['kind' => 'att.error.not_hand_added']);
        $this->assertSame(SessionKind::Extra, $training->fresh()->kind);
    }

    #[Test]
    public function moving_an_extra_session_away_leaves_the_regular_slot_of_that_day(): void
    {
        $u15 = $this->category();
        $this->mondaySchedule($u15);
        // Monday 9 November, at another hour than the weekly slot.
        $extra = $this->makeTraining($u15, ['date' => '2026-11-09', 'start_time' => '20:00', 'end_time' => '21:00']);

        $this->edit($extra, ['date' => '2026-11-10', 'start_time' => '20:00', 'end_time' => '21:00'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-09', $extra->fresh()->moved_from);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 11);

        $this->assertSame(1, TrainingSession::where('kind', SessionKind::Regular->value)->where('date', '2026-11-09')->count());
    }

    #[Test]
    public function a_category_dropped_by_switching_pre_season_to_extra_gets_its_regular_slot_back(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $this->mondaySchedule($u17);
        // Monday 5 October 18:00, the U17 weekly slot, joined by U17.
        $training = $this->makeTraining($u15, ['kind' => SessionKind::Preseason]);
        $training->categories()->syncWithoutDetaching([$u17->id]);
        $generator = app(SessionGenerator::class);
        $generator->forMonth($u17->id, 2026, 10);
        $this->assertSame(0, TrainingSession::where('category_id', $u17->id)->where('date', '2026-10-05')->count());

        $this->edit($training, ['kind' => 'extra'])->assertSessionHasNoErrors();
        $generator->forMonth($u17->id, 2026, 10);

        $this->assertSame(1, TrainingSession::where('category_id', $u17->id)->where('kind', SessionKind::Regular->value)
            ->where('date', '2026-10-05')->where('start_time', '18:00')->count());
    }

    #[Test]
    public function only_a_slot_change_bumps_the_generation_version(): void
    {
        $training = $this->makeTraining($this->category());

        $version = GenerationMarks::version();
        $this->edit($training, ['title' => 'Only a title', 'notes' => 'Bring water'])->assertSessionHasNoErrors();
        $this->assertSame($version, GenerationMarks::version());

        $this->edit($training, ['date' => '2026-10-06', 'start_time' => '18:00', 'end_time' => '19:30'])->assertSessionHasNoErrors();
        $this->assertGreaterThan($version, GenerationMarks::version());
    }

    private function mondaySchedule(Category $category): TrainingSchedule
    {
        return TrainingSchedule::create([
            'category_id' => $category->id, 'weekday' => 1,
            'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ]);
    }

    #[Test]
    public function the_session_page_carries_what_the_edit_dialog_needs(): void
    {
        $u15 = $this->category();
        $this->category('U17');
        $foot = Branch::create(['name' => 'Foot']);
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $training))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allCategories', 2)
                ->where('branches.0.id', $foot->id)
                ->has('playerStatuses')
                ->has('session.roster_status_ids'));
    }
}
