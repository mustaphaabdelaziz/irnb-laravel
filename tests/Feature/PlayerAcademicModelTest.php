<?php

namespace Tests\Feature;

use App\Enums\EducationLevel;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\PlayerAcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicModelTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function student(): Player
    {
        return Player::create([
            'membership_id' => '6'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'M'.$this->seq, 'lastname' => 'Test', 'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function year(Player $p, int $year, string $level = 'secondary'): PlayerAcademicYear
    {
        return $p->academicYears()->create(['academic_year' => $year, 'education_level' => $level]);
    }

    #[Test]
    public function primary_is_out_of_10_and_others_out_of_20(): void
    {
        $this->assertSame(10, EducationLevel::Primary->scale());
        $this->assertSame(20, EducationLevel::Secondary->scale());
        $this->assertSame(5.0, EducationLevel::Primary->passMark());
    }

    #[Test]
    public function year_average_is_the_mean_of_entered_trimesters_and_provisional_until_three(): void
    {
        $y = $this->year($this->student(), 2025);
        $y->records()->create(['period' => 'T2', 'gpa' => 11]);
        $y->records()->create(['period' => 'T1', 'gpa' => 12.5]);

        $y->refresh();
        $this->assertSame(['T1', 'T2'], $y->records->pluck('period')->all());
        $this->assertSame(11.75, $y->average());
        $this->assertTrue($y->isProvisional());

        $y->records()->create(['period' => 'T3', 'gpa' => 14]);
        $y->refresh();
        $this->assertSame(12.5, $y->average());
        $this->assertFalse($y->isProvisional());
        $this->assertNull($this->year($this->student(), 2025)->average());
    }

    #[Test]
    public function latest_on_20_uses_the_latest_trimester_and_converts_primary(): void
    {
        $a = $this->student();
        $this->year($a, 2024)->records()->create(['period' => 'T3', 'gpa' => 15]);
        $this->year($a, 2025)->records()->create(['period' => 'T1', 'gpa' => 9]);   // later year wins
        $b = $this->student();
        $yb = $this->year($b, 2025, 'primary');
        $yb->records()->create(['period' => 'T1', 'gpa' => 4]);
        $yb->records()->create(['period' => 'T2', 'gpa' => 7]);                      // 7/10 → 14/20
        $c = $this->student();

        $latest = DB::table('players')
            ->selectRaw('players.id, ('.PlayerAcademicRecord::latestOn20Sql().') as v')
            ->pluck('v', 'id');

        $this->assertEquals(9.0, (float) $latest[$a->id]);
        $this->assertEquals(14.0, (float) $latest[$b->id]);
        $this->assertNull($latest[$c->id]);
    }

    #[Test]
    public function deleting_a_year_deletes_its_trimesters_and_player_reaches_records_through_years(): void
    {
        $p = $this->student();
        $y = $this->year($p, 2025);
        $y->records()->create(['period' => 'T1', 'gpa' => 12]);
        $this->assertSame(1, $p->academicRecords()->count());

        $y->delete();
        $this->assertDatabaseCount('player_academic_records', 0);
    }
}
