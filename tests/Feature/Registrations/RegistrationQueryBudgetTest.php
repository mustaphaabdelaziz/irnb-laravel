<?php

namespace Tests\Feature\Registrations;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/**
 * The real guard is scale: 1,920 players must cost no more queries than 60. The
 * ceilings catch a slow creep that stays flat with size.
 */
class RegistrationQueryBudgetTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private const FEW = 60;

    private const MANY = 1920;

    private const LIST_CEILING = 70;

    private const DASHBOARD_CEILING = 90;

    private const ROSTER_CEILING = 12;

    private int $seeded = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    /** Bulk rows, so the test stays quick. Every eighth player is in both branches. */
    private function seedUpTo(int $total): void
    {
        $status = PlayerStatus::where('code', 'registered')->value('id');
        $now = now()->toDateTimeString();

        foreach (array_chunk(range($this->seeded + 1, $total), 200) as $numbers) {
            DB::table('players')->insert(array_map(fn (int $n) => [
                'firstname' => "Bulk{$n}", 'lastname' => 'Test', 'membership_id' => "FB-2020-{$n}", 'join_year' => 2020,
                'category_id' => $this->senior->id, 'status_id' => $status, 'created_at' => $now, 'updated_at' => $now,
            ], $numbers));

            $ids = DB::table('players')->whereIn('membership_id', array_map(fn (int $n) => "FB-2020-{$n}", $numbers))->pluck('id', 'membership_id');
            $rows = [];
            foreach ($numbers as $n) {
                $playerId = $ids["FB-2020-{$n}"];
                $rows[] = ['branch_id' => $this->foot->id, 'player_id' => $playerId, 'membership_id' => "FB-2020-{$n}", 'category_id' => $this->senior->id, 'status_id' => $status, 'legacy_join_year' => 2020, 'created_at' => $now, 'updated_at' => $now];
                if ($n % 8 === 0) {
                    $rows[] = ['branch_id' => $this->swim->id, 'player_id' => $playerId, 'membership_id' => "SW-2020-{$n}", 'category_id' => $this->u15->id, 'status_id' => $status, 'legacy_join_year' => 2020, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('branch_player')->insert($rows);
        }
        $this->seeded = $total;
    }

    private function queries(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @param callable(): void $call */
    private function assertBudget(callable $call, int $ceiling): void
    {
        $this->seedUpTo(self::FEW);
        $few = $this->queries($call);
        $this->seedUpTo(self::MANY);
        $many = $this->queries($call);

        $this->assertLessThanOrEqual($few + 2, $many, "Queries grew with players: {$few} at ".self::FEW.", {$many} at ".self::MANY);
        $this->assertLessThanOrEqual($ceiling, $many, "{$many} queries, ceiling {$ceiling}");
    }

    #[Test]
    public function the_players_list_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('players.index', ['per_page' => 200]))->assertOk(), self::LIST_CEILING);
    }

    #[Test]
    public function the_players_list_of_one_branch_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('players.index', ['per_page' => 200, 'branch_id' => [$this->swim->id]]))->assertOk(), self::LIST_CEILING);
    }

    #[Test]
    public function the_members_dashboard_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('dashboard', ['tab' => 'members']))->assertOk(), self::DASHBOARD_CEILING);
    }

    #[Test]
    public function the_members_dashboard_of_one_branch_stays_flat(): void
    {
        $this->assertBudget(fn () => $this->get(route('dashboard', ['tab' => 'members', 'branch' => $this->swim->id]))->assertOk(), self::DASHBOARD_CEILING);
    }

    #[Test]
    public function the_session_roster_stays_flat(): void
    {
        $session = TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'date' => '2026-05-14',
            'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);

        $this->assertBudget(fn () => app(Roster::class)->forSession($session), self::ROSTER_CEILING);
    }
}
