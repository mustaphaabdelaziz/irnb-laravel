<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class PreseasonMultiCategoryTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function slot(array $extra = []): array
    {
        return $extra + ['date' => '2026-09-05', 'start_time' => '09:00', 'end_time' => '10:30'];
    }

    private function makeTraining(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + $this->slot([
            'category_id' => $category->id, 'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]));
    }

    #[Test]
    public function a_preseason_session_is_created_for_several_categories(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];

        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'category_ids' => [$u15->id, $u17->id], 'kind' => 'preseason',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_created');

        $training = TrainingSession::sole();
        $this->assertSame($u15->id, $training->category_id);
        $this->assertSame([$u15->id, $u17->id], $training->categoryIds());
    }

    #[Test]
    public function an_extra_session_stays_single_category(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];

        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'category_ids' => [$u17->id], 'kind' => 'extra',
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$u15->id], TrainingSession::sole()->categoryIds());
    }

    #[Test]
    public function the_slot_check_covers_every_category_of_the_session(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $admin = $this->admin();
        $this->makeTraining($u17, ['kind' => SessionKind::Regular]);

        // U17 already trains at that time: a joint U15 + U17 session is refused, U15 alone is fine.
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'category_ids' => [$u17->id], 'kind' => 'preseason',
        ]))->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);

        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'kind' => 'preseason',
        ]))->assertSessionHasNoErrors();

        // U15 now takes part in that slot, so an extra U15 session there is refused too.
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'kind' => 'extra',
        ]))->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);
    }

    #[Test]
    public function moving_checks_the_slot_for_every_category(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $joint = $this->makeTraining($u15);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $this->makeTraining($u17, ['date' => '2026-09-06', 'kind' => SessionKind::Regular]);

        $this->actingAs($this->admin())->post(route('attendance.sessions.move', $joint), $this->slot(['date' => '2026-09-06']))
            ->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);

        $this->assertSame('2026-09-05', $joint->fresh()->date);
    }

    #[Test]
    public function the_categories_of_an_unmarked_preseason_session_can_be_changed(): void
    {
        [$u15, $u17, $u19] = [$this->category(), $this->category('U17'), $this->category('U19')];
        $training = $this->makeTraining($u15);

        $this->actingAs($this->admin())->put(route('attendance.sessions.categories', $training), ['category_ids' => [$u17->id, $u19->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_categories_saved');

        $training->refresh();
        // The primary category was removed, so the first chosen one takes over.
        $this->assertSame($u17->id, $training->category_id);
        $this->assertSame([$u17->id, $u19->id], $training->categoryIds());
    }

    #[Test]
    public function categories_cannot_change_once_marked_for_other_kinds_or_into_a_taken_slot(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $admin = $this->admin();

        $regular = $this->makeTraining($u15, ['kind' => SessionKind::Regular, 'date' => '2026-09-01']);
        $this->actingAs($admin)->put(route('attendance.sessions.categories', $regular), ['category_ids' => [$u15->id, $u17->id]])
            ->assertSessionHasErrors(['category_ids' => 'att.error.not_preseason']);

        $marked = $this->makeTraining($u15, ['date' => '2026-09-02', 'state' => SessionState::Held]);
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);
        $this->actingAs($admin)->put(route('attendance.sessions.categories', $marked), ['category_ids' => [$u15->id, $u17->id]])
            ->assertSessionHasErrors(['category_ids' => 'att.error.already_marked']);

        $this->makeTraining($u17, ['date' => '2026-09-03', 'kind' => SessionKind::Regular]);
        $clash = $this->makeTraining($u15, ['date' => '2026-09-03']);
        $this->actingAs($admin)->put(route('attendance.sessions.categories', $clash), ['category_ids' => [$u15->id, $u17->id]])
            ->assertSessionHasErrors(['category_ids' => 'att.error.duplicate']);

        $this->assertSame([$u15->id], $clash->fresh()->categoryIds());
    }

    #[Test]
    public function changing_categories_needs_attendance_edit(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view', 'add']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($user)->put(route('attendance.sessions.categories', $training), ['category_ids' => [$u15->id]])
            ->assertForbidden();
    }

    #[Test]
    public function the_session_screen_lists_its_categories_and_offers_all_for_preseason(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $this->category('U19');
        $this->player($u15);
        $this->player($u17);
        $training = $this->makeTraining($u17);
        $training->categories()->syncWithoutDetaching([$u15->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $training))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Session')
                ->has('session.categories', 2)
                ->where('session.categories.0.id', $u17->id)
                ->where('session.category', 'U17 · U15')
                ->has('allCategories', 3)
                ->where('rows.0.category', 'U15'));
    }
}
