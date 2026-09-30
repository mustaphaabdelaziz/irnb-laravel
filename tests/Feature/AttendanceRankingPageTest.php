<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRankingPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // October 2026, season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    /** Marks $player at $trainings[i] with $codes[i]: P, R (late 10 min), AE (excused), AN; null = no mark. */
    private function marks(Player $player, array $trainings, array $codes): void
    {
        foreach ($codes as $i => $code) {
            if ($code === null) {
                continue;
            }
            [$status, $extra] = match ($code) {
                'P' => [AttendanceStatus::Present, []],
                'R' => [AttendanceStatus::Late, ['minutes' => 10]],
                'AE' => [AttendanceStatus::AbsentExcused, ['reason' => 'illness']],
                'AN' => [AttendanceStatus::AbsentUnexcused, []],
            };
            Attendance::create(array_merge([
                'training_session_id' => $trainings[$i]->id, 'player_id' => $player->id,
                'category_id' => $player->category_id, 'status' => $status,
            ], $extra));
        }
    }

    /**
     * U15, five October sessions: A 100 %, B and G 95 % (one late each; B first by id),
     * C 80 %, D 60 %, E only 4 sessions (not ranked). C was also unexcused in September.
     *
     * @return array<string, mixed>
     */
    private function seedData(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $s = array_map(fn (string $date) => $this->training($u15, $date), ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07', '2026-10-09']);
        $p = [
            'a' => $this->player($u15), 'b' => $this->player($u15), 'c' => $this->player($u15),
            'd' => $this->player($u15), 'e' => $this->player($u15), 'g' => $this->player($u15),
        ];
        $this->marks($p['a'], $s, ['P', 'P', 'P', 'P', 'P']);
        $this->marks($p['b'], $s, ['P', 'P', 'P', 'P', 'R']);
        $this->marks($p['c'], $s, ['P', 'P', 'P', 'P', 'AE']);
        $this->marks($p['d'], $s, ['P', 'P', 'P', 'P', 'AN']);
        $this->marks($p['e'], $s, ['P', 'P', 'P', 'AE', null]);
        $this->marks($p['g'], $s, ['R', 'P', 'P', 'P', 'P']);
        $this->marks($p['c'], [$this->training($u15, '2026-09-15')], ['AN']);
        $this->player($u17);

        return ['u15' => $u15, 'u17' => $u17, ...$p];
    }

    #[Test]
    public function the_first_category_and_the_current_month_are_ranked_by_default(): void
    {
        $x = $this->seedData();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.ranking'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('categoryId', $x['u15']->id)
                ->has('categories', 2)
                ->where('period.type', 'month')
                ->where('period.month', '2026-10')
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-31')
                ->where('period.label', 'octobre 2026')
                ->has('seasons', 3)
                ->where('seasons.0.start_year', 2026)
                ->where('seasons.0.label', '2026/27')
                ->where('minExpected', 5)
                ->where('unranked', 1)
                ->has('rows', 5)
                ->where('rows.0.player_id', $x['a']->id)
                ->where('rows.0.rank', 1)
                ->where('rows.0.name', $x['a']->fullname)
                ->where('rows.0.expected', 5)
                ->where('rows.0.present', 5)
                ->where('rows.0.score_pct', 100)
                ->where('rows.1.player_id', $x['b']->id)     // tie at 95 %: same unexcused and lates, lower id first
                ->where('rows.1.score_pct', 95)
                ->where('rows.2.player_id', $x['g']->id)
                ->where('rows.2.rank', 3)
                ->where('rows.3.player_id', $x['c']->id)
                ->where('rows.3.present', 4)
                ->where('rows.4.player_id', $x['d']->id)
                ->where('rows.4.score_pct', 60));
    }

    #[Test]
    public function a_season_can_be_ranked(): void
    {
        $x = $this->seedData();

        $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.ranking', ['category_id' => $x['u15']->id, 'type' => 'season', 'season' => 2026]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.type', 'season')
                ->where('period.season', 2026)
                ->where('period.from', '2026-09-01')
                ->where('period.to', '2027-08-31')
                ->where('period.label', 'Saison 2026/27')
                // C now has 6 sessions with a September unexcused: 3/6 = 50 %, after D.
                ->where('rows.3.player_id', $x['d']->id)
                ->where('rows.4.player_id', $x['c']->id)
                ->where('rows.4.score_pct', 50));
    }

    #[Test]
    public function a_category_without_ranked_players_shows_an_empty_list(): void
    {
        $x = $this->seedData();

        $this->actingAs($this->admin())->get(route('attendance.ranking', ['category_id' => $x['u17']->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $x['u17']->id)
                ->has('rows', 0)
                ->where('unranked', 0));
    }

    #[Test]
    public function a_departed_player_is_excluded_and_the_next_one_takes_first(): void
    {
        $x = $this->seedData();
        $x['a']->update(['status_id' => PlayerStatus::where('code', 'left')->value('id')]);   // A was 100 %, rank 1

        $this->actingAs($this->userIn('fr'))->get(route('attendance.ranking', ['category_id' => $x['u15']->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unranked', 1)   // still just E; A is gone entirely, not "below the minimum"
                ->has('rows', 4)
                ->where('rows.0.player_id', $x['b']->id)
                ->where('rows.0.rank', 1)
                ->where('rows.1.player_id', $x['g']->id)
                ->where('rows.2.player_id', $x['c']->id)
                ->where('rows.3.player_id', $x['d']->id));
    }

    #[Test]
    public function the_unranked_count_only_counts_active_players_below_the_minimum(): void
    {
        $x = $this->seedData();
        $x['e']->update(['archived' => true]);   // E was the only one below the minimum

        $this->actingAs($this->userIn('fr'))->get(route('attendance.ranking', ['category_id' => $x['u15']->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unranked', 0)
                ->has('rows', 5));
    }

    #[Test]
    public function the_page_needs_attendance_view_only(): void
    {
        $this->seedData();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.ranking'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.ranking'))->assertForbidden();
    }
}
