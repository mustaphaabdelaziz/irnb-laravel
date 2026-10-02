<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSessionTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function makeSession(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function an_unsaved_session_shows_the_expected_roster_as_present_with_the_last_coach(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $this->makeSession($u15, ['date' => '2026-09-28', 'coach' => 'Karim', 'state' => SessionState::Held]);
        $session = $this->makeSession($u15);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Session')
                ->where('saved', false)
                ->where('lastCoach', 'Karim')
                ->has('rows', 1)
                ->where('rows.0.player_id', $a->id)
                ->where('rows.0.status', 'present')
                ->has('candidates', 0));
    }

    #[Test]
    public function marks_are_saved_and_validated(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $session = $this->makeSession($u15);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $a->id, 'status' => 'late'], ['player_id' => $b->id, 'status' => 'absent_excused']],
        ])->assertSessionHasErrors(['marks.0.minutes', 'marks.1.reason']);

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'coach' => 'Karim', 'title' => 'Endurance',
            'marks' => [
                ['player_id' => $a->id, 'status' => 'late', 'minutes' => 10],
                ['player_id' => $b->id, 'status' => 'absent_excused', 'reason' => 'illness', 'note' => 'Flu'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_saved');

        $this->assertSame(SessionState::Held, $session->fresh()->state);
        $this->assertSame('Endurance', $session->fresh()->title);
        $this->assertSame(AttendanceStatus::Late->value, $session->attendances()->where('player_id', $a->id)->value('status'));
    }

    #[Test]
    public function a_frozen_row_keeps_the_category_the_player_was_marked_with(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $u13 = $this->category('U13');
        $player = $this->player($u15);
        $session = $this->makeSession($u15, ['kind' => SessionKind::Preseason]);
        $session->categories()->syncWithoutDetaching([$u17->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $player->id, 'status' => 'present']],
        ])->assertSessionHasNoErrors();
        $player->update(['category_id' => $u13->id]);

        $this->actingAs($admin)->get(route('attendance.sessions.show', $session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Session')
                ->where('rows.0.category', 'U15'));
    }

    #[Test]
    public function marking_needs_attendance_edit(): void
    {
        $u15 = $this->category();
        $session = $this->makeSession($u15);
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view']]]);
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('attendance.sessions.show', $session))->assertOk();
        $this->actingAs($viewer)->put(route('attendance.sessions.marks', $session), [
            'marks' => [['player_id' => $this->player($u15)->id, 'status' => 'present']],
        ])->assertForbidden();
    }
}
