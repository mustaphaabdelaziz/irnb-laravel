<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingSessionTitleTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function theme_is_renamed_to_title_keeping_sessions_and_marks(): void
    {
        $this->assertTrue(Schema::hasColumn('training_sessions', 'title'));
        $this->assertFalse(Schema::hasColumn('training_sessions', 'theme'));

        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'title' => 'Endurance',
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);
        $migration = require database_path('migrations/2026_09_29_200001_rename_training_sessions_theme_to_title.php');

        // Back and forth, then a second run as on every desktop boot. A table
        // rebuild here would cascade-delete the marks (see the migration).
        $migration->down();
        $this->assertTrue(Schema::hasColumn('training_sessions', 'theme'));
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('training_sessions', 'title'));
        $this->assertFalse(Schema::hasColumn('training_sessions', 'theme'));
        $this->assertSame('Endurance', $session->fresh()->title);
        $this->assertSame(1, Attendance::count());
    }

    #[Test]
    public function the_title_is_saved_with_the_marks_up_to_150_characters(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        $admin = $this->admin();
        $marks = [['player_id' => $a->id, 'status' => 'present']];

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), ['title' => str_repeat('x', 151), 'marks' => $marks])
            ->assertSessionHasErrors('title');

        $title = str_repeat('Running 7.2 km in 40 min ', 6); // 150 characters
        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), ['title' => $title, 'marks' => $marks])
            ->assertSessionHasNoErrors();

        $this->assertSame(trim($title), $session->fresh()->title);
    }

    #[Test]
    public function a_new_session_takes_a_title_and_the_calendar_shows_it(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.sessions.store'), [
            'category_id' => $u15->id, 'kind' => 'preseason', 'date' => '2026-10-03', 'start_time' => '09:00', 'end_time' => '10:30',
            'title' => 'Running 7.2 km in 40 min',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Running 7.2 km in 40 min', TrainingSession::sole()->title);

        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('sessions.0.title', 'Running 7.2 km in 40 min'));

        $this->actingAs($admin)->get(route('attendance.sessions.show', TrainingSession::sole()))
            ->assertInertia(fn (Assert $page) => $page->where('session.title', 'Running 7.2 km in 40 min'));
    }
}
