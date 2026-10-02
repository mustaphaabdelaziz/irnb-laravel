<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCategoryPivotTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** A pre-season session of $primary on Monday 2026-10-05 18:00, shared with $others. */
    private function joint(Category $primary, array $others, array $extra = []): TrainingSession
    {
        $training = TrainingSession::create($extra + [
            'category_id' => $primary->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]);
        $training->categories()->syncWithoutDetaching(array_map(fn (Category $c) => $c->id, $others));

        return $training;
    }

    /** @return array<int, array{0: int, 1: int}> [session id, category id] pairs */
    private function links(): array
    {
        return DB::table('training_session_category')->orderBy('training_session_id')->orderBy('category_id')->get()
            ->map(fn ($row) => [(int) $row->training_session_id, (int) $row->category_id])->all();
    }

    #[Test]
    public function every_new_session_is_linked_to_its_primary_category(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $manual = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-06', 'start_time' => '09:00', 'end_time' => '10:00',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);
        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        // Four October Mondays plus the extra session, each linked once.
        $this->assertSame(5, TrainingSession::count());
        $this->assertSame(5, DB::table('training_session_category')->where('category_id', $u15->id)->count());
        $this->assertContains([$manual->id, $u15->id], $this->links());
    }

    #[Test]
    public function an_unlinked_generated_session_is_linked_on_the_next_view(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);
        $session = TrainingSession::where('category_id', $u15->id)->first();
        DB::table('training_session_category')->where('training_session_id', $session->id)->delete();
        // The generation mark is written in the same transaction as the sessions
        // and their links, so a month left half-linked (an interrupted run before
        // marks existed) has no mark.
        DB::table('session_generation_marks')->delete();

        $created = app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        $this->assertSame(0, $created);
        $this->assertContains([$session->id, $u15->id], $this->links());
    }

    #[Test]
    public function the_backfill_links_existing_sessions_once(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);
        $b = TrainingSession::create(['category_id' => $u17->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);
        DB::table('training_session_category')->delete();

        $migration = require database_path('migrations/2026_09_29_200002_create_training_session_category_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame([[$a->id, $u15->id], [$b->id, $u17->id]], $this->links());
    }

    #[Test]
    public function the_generator_leaves_a_slot_taken_by_a_joint_session_free(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        TrainingSchedule::create(['category_id' => $u17->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $this->joint($u15, [$u17]);

        $this->assertSame(3, app(SessionGenerator::class)->forMonth($u17->id, 2026, 10));
        $this->assertSame(
            ['2026-10-12', '2026-10-19', '2026-10-26'],
            TrainingSession::where('category_id', $u17->id)->orderBy('date')->pluck('date')->all(),
        );
    }

    #[Test]
    public function the_expected_roster_of_a_joint_session_covers_all_its_categories(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $this->player($u17, ['archived' => true]);
        $this->player($this->category('U19'));
        $training = $this->joint($u15, [$u17]);

        $this->assertSame([$a->id, $b->id], app(Roster::class)->forSession($training)->pluck('id')->all());
        $this->assertSame([$a->id, $b->id], app(Roster::class)->expected([$u15->id, $u17->id], '2026-10-05')->pluck('id')->all());
        $this->assertSame([$u15->id, $u17->id], $training->categoryIds());
    }

    #[Test]
    public function each_category_grid_shows_a_joint_session_with_its_full_roster(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $training = $this->joint($u15, [$u17]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.grid', ['category_id' => $u17->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Grid')
                ->has('sessions', 1)
                ->where('sessions.0.id', $training->id)
                ->has('rows', 2)
                ->where("cells.{$a->id}.{$training->id}", '')
                ->where("cells.{$b->id}.{$training->id}", ''));

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'P', $b->id => 'AN']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $training->attendances()->count());
    }

    #[Test]
    public function the_session_screen_lists_the_players_of_every_category(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $this->player($u15);
        $this->player($u17);
        $training = $this->joint($u15, [$u17]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $training))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->has('candidates', 0));
    }
}
