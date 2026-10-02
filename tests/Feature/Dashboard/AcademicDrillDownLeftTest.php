<?php

namespace Tests\Feature\Dashboard;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\MemberStats;
use App\Services\Dashboard\ModuleStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The players list hides "Left the club" by default, so the dashboard figures
 * that drill down into it (academic block, certificates, active players tile)
 * leave Left players out too: the number clicked is the number listed.
 */
class AcademicDrillDownLeftTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00'); // current school year 2025
    }

    private function student(string $name, ?float $gpa, ?string $certificate = null, array $attributes = []): Player
    {
        $player = Player::create([
            'membership_id' => '6'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $name,
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => 0,
            ...$attributes,
        ]);

        if ($gpa !== null) {
            $player->academicYears()->create(['academic_year' => 2025, 'education_level' => 'secondary'])
                ->records()->create(['period' => 'T1', 'gpa' => $gpa, 'certificate' => $certificate]);
        }

        return $player;
    }

    private function academic(): array
    {
        return app(MemberStats::class)->get(DashboardFilters::fromRequest(Request::create('/dashboard')))['academic'];
    }

    private function listed(array $query): int
    {
        return count($this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props']['players']['data']);
    }

    #[Test]
    public function academic_counts_match_the_drill_down_lists_with_left_students(): void
    {
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->student('AtRisk', 8);
        $this->student('LeftAtRisk', 7, attributes: ['status_id' => $left]);
        $this->student('Missing', null);
        $this->student('LeftMissing', null, attributes: ['status_id' => $left]);
        $this->student('Excellent', 18, 'excellence');
        $this->student('LeftExcellent', 18, 'excellence', ['status_id' => $left]);

        $academic = $this->academic();

        $this->assertSame(3, $academic['students']);
        $this->assertSame(1, $academic['at_risk']);
        $this->assertSame(1, $academic['missing']);
        $this->assertSame(1, $academic['certificates']['excellence']);

        $this->assertSame($academic['at_risk'], $this->listed(['academic' => 'at_risk']));
        $this->assertSame($academic['missing'], $this->listed(['academic' => 'none']));
        $this->assertSame($academic['certificates']['excellence'], $this->listed(['certificate' => 'excellence']));
    }

    #[Test]
    public function the_active_players_tile_leaves_left_players_out_but_keeps_their_debt(): void
    {
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->student('Member', null, attributes: ['outstanding_debt' => 100]);
        $this->student('NoStatus', null);
        $this->student('Gone', null, attributes: ['status_id' => $left, 'outstanding_debt' => 50]);

        $strip = collect(app(ModuleStats::class)->players())->keyBy('key');

        $this->assertSame(2, $strip['players_active']['value']);
        $this->assertSame(150.0, $strip['players_debt_total']['value']);
        $this->assertSame(2, $strip['players_with_debt']['value']);
        $this->assertSame(2, $this->listed([]));
    }
}
