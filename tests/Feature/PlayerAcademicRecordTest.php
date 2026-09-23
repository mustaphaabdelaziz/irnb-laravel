<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicRecordTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makePlayer(bool $student = true): Player
    {
        return Player::create([
            'membership_id' => '8'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'S'.$this->seq,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);
    }

    private function record(Player $player, int $year, string $period, float $gpa): PlayerAcademicRecord
    {
        return $player->academicRecords()->create([
            'academic_year' => $year,
            'period' => $period,
            'gpa' => $gpa,
        ]);
    }

    #[Test]
    public function records_order_chronologically_with_annual_last_in_a_year(): void
    {
        $player = $this->makePlayer();
        $this->record($player, 2025, 'ANNUAL', 13);
        $this->record($player, 2024, 'S2', 11);
        $this->record($player, 2025, 'S1', 12);
        $this->record($player, 2024, 'S1', 9.5);

        $order = $player->academicRecords()->chronological()->get()
            ->map(fn ($r) => $r->academic_year.'-'.$r->period)->all();

        $this->assertSame(['2024-S1', '2024-S2', '2025-S1', '2025-ANNUAL'], $order);
    }

    #[Test]
    public function latest_gpa_sql_picks_the_last_record_in_chronological_order(): void
    {
        $a = $this->makePlayer();
        $this->record($a, 2024, 'S2', 15);
        $this->record($a, 2025, 'T1', 8.25);   // later year wins over higher rank
        $b = $this->makePlayer();
        $this->record($b, 2025, 'S2', 9);
        $this->record($b, 2025, 'ANNUAL', 10.5); // ANNUAL after S2
        $c = $this->makePlayer();                 // no records

        $latest = DB::table('players')
            ->selectRaw('players.id, ('.PlayerAcademicRecord::latestGpaSql().') as latest_gpa')
            ->pluck('latest_gpa', 'id');

        $this->assertEquals(8.25, (float) $latest[$a->id]);
        $this->assertEquals(10.5, (float) $latest[$b->id]);
        $this->assertNull($latest[$c->id]);
    }
}
