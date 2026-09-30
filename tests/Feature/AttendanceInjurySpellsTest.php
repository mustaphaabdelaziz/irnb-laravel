<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\InjurySpells;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceInjurySpellsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status,
        ], $extra));
    }

    private function spells(): InjurySpells
    {
        return app(InjurySpells::class);
    }

    /**
     * U15 X: P, B(injury), AE(injury) | AE(illness) | B(illness), B(injury) | P | AE(injury, the latest mark).
     * Y is always present, Z has no mark. A cancelled session between two injury marks never counts.
     *
     * @return array{0: Player, 1: Player, 2: Player}
     */
    private function seedData(): array
    {
        $u15 = $this->category('U15');
        $x = $this->player($u15);
        $y = $this->player($u15);
        $z = $this->player($u15);
        $marks = [
            ['2026-10-01', AttendanceStatus::Present, null],
            ['2026-10-02', AttendanceStatus::NotTraining, 'injury'],
            ['2026-10-03', AttendanceStatus::AbsentExcused, 'injury'],
            ['2026-10-05', AttendanceStatus::AbsentExcused, 'illness'],   // not an injury: ends the first spell
            ['2026-10-06', AttendanceStatus::NotTraining, 'illness'],     // "not training" counts whatever the reason
            ['2026-10-07', AttendanceStatus::NotTraining, 'injury'],
            ['2026-10-09', AttendanceStatus::Present, null],
            ['2026-10-12', AttendanceStatus::AbsentExcused, 'injury'],    // the latest mark: an open spell
        ];
        foreach ($marks as [$date, $status, $reason]) {
            $training = $this->training($u15, $date);
            $this->mark($training, $x, $status, ['reason' => $reason]);
            $this->mark($training, $y, AttendanceStatus::Present);
        }
        $this->mark($this->training($u15, '2026-10-06', ['start_time' => '20:00', 'end_time' => '21:00', 'state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']), $x, AttendanceStatus::Present);

        return [$x, $y, $z];
    }

    #[Test]
    public function spells_join_consecutive_injury_marks_and_the_latest_one_is_open(): void
    {
        [$x, $y, $z] = $this->seedData();

        $this->assertSame([
            ['start' => '2026-10-02', 'end' => '2026-10-03', 'sessions' => 2, 'open' => false],
            ['start' => '2026-10-06', 'end' => '2026-10-07', 'sessions' => 2, 'open' => false],
            ['start' => '2026-10-12', 'end' => '2026-10-12', 'sessions' => 1, 'open' => true],
        ], $this->spells()->all($x->id));
        $this->assertSame([], $this->spells()->all($y->id));
        $this->assertSame([], $this->spells()->all($z->id));
    }

    #[Test]
    public function the_profile_gets_the_periods_spells_newest_first_with_details_and_unmatched_ones(): void
    {
        [$x, $y] = $this->seedData();
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-06', 'body_part' => 'Cheville', 'description' => 'Entorse']);
        $stray = InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-04', 'body_part' => 'Genou']);   // no spell starts on the 4th

        $all = $this->spells()->forPlayer($x->id, '2026-10-01', '2026-10-31');

        $this->assertSame(['2026-10-12', '2026-10-06', '2026-10-02'], array_column($all['spells'], 'start'));
        $this->assertNull($all['spells'][0]['note']);
        $this->assertSame('Cheville', $all['spells'][1]['note']['body_part']);
        $this->assertSame('Entorse', $all['spells'][1]['note']['description']);
        $this->assertSame([$stray->id], array_column($all['unmatched'], 'id'));
        $this->assertSame('2026-10-04', $all['unmatched'][0]['start_date']);

        // Only the spells overlapping the period; an open spell runs until today.
        $this->assertSame(['2026-10-12', '2026-10-06'], array_column($this->spells()->forPlayer($x->id, '2026-10-07', '2026-10-31')['spells'], 'start'));
        $this->assertSame(['2026-10-12'], array_column($this->spells()->forPlayer($x->id, '2026-10-15', '2026-10-31')['spells'], 'start'));
        $this->assertSame([], $this->spells()->forPlayer($x->id, '2026-10-04', '2026-10-05')['spells']);
        $this->assertSame(['spells' => [], 'unmatched' => []], $this->spells()->forPlayer($y->id, '2026-10-01', '2026-10-31'));
    }

    #[Test]
    public function editing_an_earlier_mark_moves_the_start_and_the_detail_becomes_unmatched_not_lost(): void
    {
        [$x] = $this->seedData();
        $note = InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-06', 'body_part' => 'Cheville']);
        // The illness on the 5th was an injury after all: the first two spells join.
        Attendance::where('player_id', $x->id)
            ->where('training_session_id', TrainingSession::where('date', '2026-10-05')->value('id'))
            ->update(['status' => AttendanceStatus::NotTraining->value, 'reason' => 'injury']);

        $profile = $this->spells()->forPlayer($x->id, '2026-10-01', '2026-10-31');

        $this->assertSame(['2026-10-12', '2026-10-02'], array_column($profile['spells'], 'start'));
        $this->assertSame(5, $profile['spells'][1]['sessions']);
        $this->assertSame('2026-10-07', $profile['spells'][1]['end']);
        $this->assertSame([$note->id], array_column($profile['unmatched'], 'id'));
        $this->assertSame(1, InjuryNote::count());
    }

    #[Test]
    public function the_club_list_has_current_injuries_and_the_periods_spells(): void
    {
        [$x] = $this->seedData();
        $u17 = $this->category('U17');
        $w = $this->player($u17);
        $this->mark($this->training($u17, '2026-09-10'), $w, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u17, '2026-09-12'), $w, AttendanceStatus::Present);
        $archived = $this->player($u17);
        $this->mark($this->training($u17, '2026-10-10'), $archived, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $archived->update(['archived' => true]);
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-12', 'body_part' => 'Genou']);

        $october = $this->spells()->club('2026-10-01', '2026-10-31');

        $this->assertSame([$x->id], array_column($october['current'], 'player_id'));
        $this->assertSame('2026-10-12', $october['current'][0]['start']);
        $this->assertSame($x->fullname, $october['current'][0]['name']);
        $this->assertSame('U15', $october['current'][0]['category']);
        $this->assertSame('Genou', $october['current'][0]['note']['body_part']);
        $this->assertSame(['2026-10-12', '2026-10-06', '2026-10-02'], array_column($october['spells'], 'start'));

        $september = $this->spells()->club('2026-09-01', '2026-09-30');
        $this->assertSame([$x->id], array_column($september['current'], 'player_id'));   // current = today, whatever the period
        $this->assertSame([$w->id], array_column($september['spells'], 'player_id'));

        $this->assertSame(['current' => [], 'spells' => []], $this->spells()->club('2026-10-01', '2026-10-31', $u17->id));
    }

    #[Test]
    public function a_return_date_on_or_after_the_end_closes_the_open_spell_and_drops_it_from_current(): void
    {
        [$x] = $this->seedData();
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-12', 'returned_on' => '2026-10-15']);

        $october = $this->spells()->club('2026-10-01', '2026-10-31');

        $this->assertSame([], $october['current']);   // closed: no longer "current"
        $this->assertFalse($october['spells'][0]['open']);
        $this->assertSame('2026-10-15', $october['spells'][0]['end']);   // the overlap rule now ends at returned_on
        $this->assertSame('2026-10-15', $october['spells'][0]['returned_on']);

        $profile = $this->spells()->forPlayer($x->id, '2026-10-01', '2026-10-31');
        $this->assertFalse($profile['spells'][0]['open']);
        $this->assertSame('2026-10-15', $profile['spells'][0]['returned_on']);   // the profile JSON exposes returned_on
    }

    #[Test]
    public function a_later_injury_mark_past_the_return_date_reopens_the_spell(): void
    {
        $u15 = $this->category('U15');
        $p = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-01'), $p, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        InjuryNote::create(['player_id' => $p->id, 'start_date' => '2026-10-01', 'returned_on' => '2026-10-03']);
        $this->mark($this->training($u15, '2026-10-05'), $p, AttendanceStatus::NotTraining, ['reason' => 'injury']);   // extends the spell past the return date

        $profile = $this->spells()->forPlayer($p->id, '2026-10-01', '2026-10-31');

        $this->assertTrue($profile['spells'][0]['open']);
        $this->assertSame('2026-10-05', $profile['spells'][0]['end']);
        $this->assertSame('2026-10-03', $profile['spells'][0]['returned_on']);
    }

    #[Test]
    public function the_club_list_runs_a_fixed_number_of_queries(): void
    {
        $u15 = $this->category('U15');
        $trainings = [$this->training($u15, '2026-10-05'), $this->training($u15, '2026-10-07')];
        $add = function (int $n) use ($u15, $trainings): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                $this->mark($trainings[0], $player, AttendanceStatus::NotTraining, ['reason' => 'injury']);
                $this->mark($trainings[1], $player, AttendanceStatus::Present);
            }
        };
        $measure = function (): int {
            $spells = $this->spells();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $spells->club('2026-10-01', '2026-10-31');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $add(3);
        $few = $measure();
        $add(27);

        $this->assertSame($few, $measure());
        // The marks of injured players, their details, the players, their categories.
        $this->assertSame(4, $few);
    }

    /** Runs club() and returns [its result, the player ids whose marks its first query streamed]. */
    private function clubScanning(string $from, string $to): array
    {
        $spells = $this->spells();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $club = $spells->club($from, $to);
        DB::disableQueryLog();
        $first = DB::getQueryLog()[0];
        $scanned = array_values(array_unique(array_map(fn (object $row) => (int) $row->player_id, DB::select($first['query'], $first['bindings']))));

        return [$club, $scanned];
    }

    #[Test]
    public function an_old_closed_spell_is_neither_scanned_nor_listed(): void
    {
        [$x] = $this->seedData();
        $u17 = $this->category('U17');
        $old = $this->player($u17);
        $this->mark($this->training($u17, '2025-11-03'), $old, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u17, '2025-11-05'), $old, AttendanceStatus::Present);
        $this->mark($this->training($u17, '2026-10-08'), $old, AttendanceStatus::Present);

        [$october, $scanned] = $this->clubScanning('2026-10-01', '2026-10-31');

        $this->assertSame([$x->id], $scanned);
        $this->assertNotContains($old->id, array_column($october['spells'], 'player_id'));
        $this->assertNotContains($old->id, array_column($october['current'], 'player_id'));
    }

    #[Test]
    public function a_spell_begun_before_the_period_is_listed_with_its_true_start(): void
    {
        $u15 = $this->category('U15');
        $p = $this->player($u15);
        foreach (['2026-09-20', '2026-09-25', '2026-10-02'] as $date) {
            $this->mark($this->training($u15, $date), $p, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        }
        $this->mark($this->training($u15, '2026-10-06'), $p, AttendanceStatus::Present);

        [$october] = $this->clubScanning('2026-10-01', '2026-10-31');

        $this->assertSame([['player_id' => $p->id, 'start' => '2026-09-20', 'end' => '2026-10-02', 'sessions' => 3, 'open' => false]],
            array_map(fn (array $row) => array_intersect_key($row, array_flip(['player_id', 'start', 'end', 'sessions', 'open'])), $october['spells']));
        $this->assertSame([], $october['current']);
    }

    #[Test]
    public function an_open_spell_from_long_ago_is_current(): void
    {
        $u15 = $this->category('U15');
        $p = $this->player($u15);
        $this->mark($this->training($u15, '2025-03-01'), $p, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2025-03-04'), $p, AttendanceStatus::AbsentExcused, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2025-03-08'), $p, AttendanceStatus::NotTraining, ['reason' => 'injury']);

        [$october, $scanned] = $this->clubScanning('2026-10-01', '2026-10-31');

        $this->assertSame([$p->id], $scanned);
        $this->assertSame([$p->id], array_column($october['current'], 'player_id'));
        $this->assertSame('2025-03-04', $october['current'][0]['start']);
        $this->assertSame([$p->id], array_column($october['spells'], 'player_id'));   // open: it runs until today
    }

    #[Test]
    public function a_closed_spell_spanning_a_period_without_sessions_is_listed(): void
    {
        $u15 = $this->category('U15');
        $p = $this->player($u15);
        $this->mark($this->training($u15, '2026-07-28'), $p, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2026-09-03'), $p, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2026-09-05'), $p, AttendanceStatus::Present);

        [$august] = $this->clubScanning('2026-08-01', '2026-08-31');

        $this->assertSame([$p->id], array_column($august['spells'], 'player_id'));
        $this->assertSame('2026-07-28', $august['spells'][0]['start']);
    }

    #[Test]
    public function the_table_holds_one_detail_per_spell_start_and_its_migration_runs_again(): void
    {
        [$x] = $this->seedData();
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-02']);

        try {
            InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-02']);
            $this->fail('A second detail for the same spell start must be refused.');
        } catch (QueryException) {
            $this->assertSame(1, InjuryNote::count());
        }

        // The desktop app runs `migrate` on every boot: running it on an existing table is harmless.
        (require database_path('migrations/2026_09_30_200001_create_injury_notes_table.php'))->up();

        $this->assertTrue(Schema::hasColumns('injury_notes', ['player_id', 'start_date', 'body_part', 'description', 'returned_on', 'created_by']));
        $this->assertSame(1, InjuryNote::count());
    }
}
