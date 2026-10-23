<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InjuryNote;
use App\Services\Attendance\InjurySpells;
use App\Services\Competitions\PlayerFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchInjuriesTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2027-08-31';

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

    private function spells(): InjurySpells
    {
        return app(InjurySpells::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_23_300001_add_branch_to_injury_notes.php');
    }

    #[Test]
    public function a_spell_is_built_from_one_branchs_marks(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();

        $this->assertSame(
            [['start' => '2026-10-05', 'end' => '2026-10-12', 'sessions' => 2, 'open' => true]],
            $this->spells()->all($both->id, $foot->id),
        );
        $this->assertSame([], $this->spells()->all($both->id, $swim->id));
        // Mixed, the swimming marks would cut the spell in two and close it.
        $this->assertSame([false, false], array_column($this->spells()->all($both->id), 'open'));
    }

    #[Test]
    public function the_club_list_is_one_branchs(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();

        $football = $this->spells()->club(self::FROM, self::TO, null, $foot->id);
        $this->assertSame([$both->id], array_column($football['current'], 'player_id'));
        $this->assertSame('2026-10-05', $football['current'][0]['start']);

        $swimming = $this->spells()->club(self::FROM, self::TO, null, $swim->id);
        $this->assertSame([], $swimming['current']);
        $this->assertSame([], $swimming['spells']);
    }

    #[Test]
    public function details_are_kept_per_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();
        InjuryNote::create(['player_id' => $both->id, 'branch_id' => $foot->id, 'start_date' => '2026-10-05', 'body_part' => 'Knee']);
        InjuryNote::create(['player_id' => $both->id, 'branch_id' => $swim->id, 'start_date' => '2026-10-05', 'body_part' => 'Shoulder']);

        $football = $this->spells()->forPlayer($both->id, self::FROM, self::TO, $foot->id);
        $this->assertSame('Knee', $football['spells'][0]['note']['body_part']);
        $this->assertSame([], $football['unmatched']);

        $swimming = $this->spells()->forPlayer($both->id, self::FROM, self::TO, $swim->id);
        $this->assertSame([], $swimming['spells']);
        $this->assertSame(['Shoulder'], array_column($swimming['unmatched'], 'body_part'));

        $this->assertTrue(Schema::hasIndex('injury_notes', 'injury_notes_branch_spell_unique'));
        $this->assertFalse(Schema::hasIndex('injury_notes', 'injury_notes_player_id_start_date_unique'));
        $this->expectException(UniqueConstraintViolationException::class);
        InjuryNote::create(['player_id' => $both->id, 'branch_id' => $foot->id, 'start_date' => '2026-10-05']);
    }

    #[Test]
    public function the_card_saves_a_detail_on_its_branchs_spell(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('attendance.injury-notes.store', $both), ['start_date' => '2026-10-05', 'branch_id' => $foot->id, 'body_part' => 'Knee'])
            ->assertCreated()
            ->assertJsonPath('note.body_part', 'Knee');
        $this->assertSame($foot->id, InjuryNote::sole()->branch_id);

        // No swimming spell starts that day.
        $this->actingAs($admin)->postJson(route('attendance.injury-notes.store', $both), ['start_date' => '2026-10-05', 'branch_id' => $swim->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
        // Once the club has branches, a detail names its branch.
        $this->actingAs($admin)->postJson(route('attendance.injury-notes.store', $both), ['start_date' => '2026-10-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    #[Test]
    public function the_backfill_gives_a_detail_its_spells_branch_else_the_largest(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'both' => $both] = $this->injuredFootballer();
        // Swimming has the most players, so only the injury mark can send a detail to football.
        $swim->players()->attach([$this->player($u15)->id, $this->player($u15)->id]);
        $onSpell = InjuryNote::create(['player_id' => $both->id, 'start_date' => '2026-10-05']);
        $noMark = InjuryNote::create(['player_id' => $both->id, 'start_date' => '2026-09-15']);

        $this->migration()->up();   // a second run: every step finds its work done, then backfills

        $this->assertSame($foot->id, $onSpell->fresh()->branch_id);
        $this->assertSame($swim->id, $noMark->fresh()->branch_id);
    }

    #[Test]
    public function without_branches_the_backfill_leaves_details_club_wide(): void
    {
        $note = InjuryNote::create(['player_id' => $this->player($this->category())->id, 'start_date' => '2026-10-05']);

        $this->migration()->backfill();

        $this->assertNull($note->fresh()->branch_id);
    }

    #[Test]
    public function the_clubs_first_branch_adopts_club_wide_details(): void
    {
        $note = InjuryNote::create(['player_id' => $this->player($this->category())->id, 'start_date' => '2026-10-05']);

        $branch = Branch::create(['name' => 'Football']);

        $this->assertSame($branch->id, $note->fresh()->branch_id);
    }

    #[Test]
    public function deleting_an_unused_branch_drops_its_details(): void
    {
        $this->twoSports();
        $tennis = Branch::create(['name' => 'Tennis']);
        InjuryNote::create(['player_id' => $this->player($this->category())->id, 'branch_id' => $tennis->id, 'start_date' => '2026-10-05']);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $tennis))->assertSessionHas('success');

        $this->assertSame(0, InjuryNote::count());
    }

    #[Test]
    public function the_match_badge_flags_a_player_injured_in_any_branch(): void
    {
        ['both' => $both] = $this->injuredFootballer();

        $this->assertArrayHasKey($both->id, app(PlayerFlags::class)->injured());
    }

    #[Test]
    public function the_branch_adds_no_query_to_the_club_list(): void
    {
        ['foot' => $foot] = $this->injuredFootballer();
        $count = function (?int $branchId): int {
            $spells = $this->spells();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $spells->club(self::FROM, self::TO, null, $branchId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(null), $count($foot->id));
    }
}
