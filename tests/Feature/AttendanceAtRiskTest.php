<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceAtRiskTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    /** Six held sessions of $category in October, every other day from the 1st. */
    private function october(Category $category): array
    {
        return array_map(fn (string $date) => TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]), ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07', '2026-10-09', '2026-10-11']);
    }

    /** Marks $player at $trainings[i] with $codes[i]: P, R (late 10 min), AE (excused, illness), AN; null = no mark. */
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

    private function risk(): AtRisk
    {
        return app(AtRisk::class);
    }

    /** @return array<string, Player> */
    private function seedClub(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $s = $this->october($u15);
        $p = [
            'good' => $this->player($u15),        // Test001 P1
            'low' => $this->player($u15),         // Test002 P2
            'streakOnly' => $this->player($u15),  // Test003 P3
            'both' => $this->player($u15),        // Test004 P4
            'archived' => $this->player($u15),    // Test005 P5
            'left' => $this->player($u15),        // Test006 P6
            'moved' => $this->player($u17),       // Test007 P7: now in U17, marked in U15's sessions
        ];
        $this->marks($p['good'], $s, ['P', 'P', 'P', 'P', 'P', 'P']);          // 100 %
        $this->marks($p['low'], $s, ['P', 'P', 'R', 'AE', 'AE', null]);         // 2.75 / 5 = 55 %
        $this->marks($p['streakOnly'], $s, ['AN', 'AN', 'AN', 'P', null, null]); // 4 sessions: no score rule
        $this->marks($p['both'], $s, ['P', 'P', 'P', 'AN', 'AN', 'AN']);       // 0 % and 3 in a row, still running
        $this->marks($p['archived'], $s, ['P', 'P', 'P', 'AN', 'AN', 'AN']);
        $this->marks($p['left'], $s, ['P', 'P', 'P', 'AN', 'AN', 'AN']);
        $this->marks($p['moved'], $s, ['AN', 'AN', 'AN', null, null, null]);   // 3 sessions: streak only
        $p['archived']->update(['archived' => true]);
        $p['left']->update(['status_id' => Player::leftStatusId(), 'left_at' => '2026-10-15']);

        return $p;
    }

    #[Test]
    public function the_thresholds_come_from_the_settings(): void
    {
        $this->assertSame(['min_score_pct' => 60, 'unexcused_streak' => 3, 'min_expected' => 5], $this->risk()->thresholds());
    }

    #[Test]
    public function the_list_holds_active_players_flagged_by_either_rule_worst_first(): void
    {
        $p = $this->seedClub();

        $rows = $this->risk()->list(self::FROM, self::TO);

        // Three at 0 % (by name), then 55 %. Good, archived and left players are not listed.
        $this->assertSame([$p['streakOnly']->id, $p['both']->id, $p['moved']->id, $p['low']->id], array_column($rows, 'player_id'));

        $both = $rows[1];
        $this->assertSame($p['both']->fullname, $both['name']);
        $this->assertSame('U15', $both['category']);
        $this->assertSame(6, $both['expected']);
        $this->assertSame(0.0, $both['score_pct']);
        $this->assertSame(3, $both['unexcused']);
        $this->assertSame(3, $both['current_streak']);
        $this->assertSame(3, $both['longest_streak']);
        $this->assertSame('2026-10-11', $both['last_date']);
        $this->assertTrue($both['low_score']);
        $this->assertTrue($both['streak']);

        $streakOnly = $rows[0];
        $this->assertFalse($streakOnly['low_score']);
        $this->assertTrue($streakOnly['streak']);
        $this->assertSame(0, $streakOnly['current_streak']);
        $this->assertSame(3, $streakOnly['longest_streak']);

        $low = $rows[3];
        $this->assertTrue($low['low_score']);
        $this->assertFalse($low['streak']);
        $this->assertSame(55.0, $low['score_pct']);
        $this->assertSame(0, $low['unexcused']);
        $this->assertSame('2026-10-09', $low['last_date']);
    }

    #[Test]
    public function the_category_filter_uses_the_players_current_category(): void
    {
        $p = $this->seedClub();

        $this->assertSame([$p['moved']->id], array_column($this->risk()->list(self::FROM, self::TO, $p['moved']->category_id), 'player_id'));
        $this->assertSame(
            [$p['streakOnly']->id, $p['both']->id, $p['low']->id],
            array_column($this->risk()->list(self::FROM, self::TO, $p['both']->category_id), 'player_id'),
        );
    }

    #[Test]
    public function a_rule_set_to_zero_is_off(): void
    {
        $p = $this->seedClub();

        AttendanceSettings::save(['alerts' => ['min_score_pct' => 0]]);
        $this->assertSame([$p['streakOnly']->id, $p['both']->id, $p['moved']->id], array_column($this->risk()->list(self::FROM, self::TO), 'player_id'));

        AttendanceSettings::save(['alerts' => ['min_score_pct' => 60, 'unexcused_streak' => 0]]);
        $this->assertSame([$p['both']->id, $p['low']->id], array_column($this->risk()->list(self::FROM, self::TO), 'player_id'));
    }

    #[Test]
    public function one_players_flags_for_the_profile(): void
    {
        $p = $this->seedClub();
        $stats = app(AttendanceStats::class);
        $rowOf = fn (Player $player) => $stats->players(self::FROM, self::TO, null, $player->id)[$player->id];

        $both = $this->risk()->forPlayer($p['both']->id, self::FROM, self::TO, $rowOf($p['both']));
        $good = $this->risk()->forPlayer($p['good']->id, self::FROM, self::TO, $rowOf($p['good']));

        $this->assertSame([
            'at_risk' => true, 'low_score' => true, 'streak' => true, 'score_pct' => 0.0,
            'current_streak' => 3, 'longest_streak' => 3,
            'min_score_pct' => 60, 'unexcused_streak' => 3, 'min_expected' => 5,
        ], $both);
        $this->assertFalse($good['at_risk']);
        $this->assertSame(0, $good['longest_streak']);
    }

    #[Test]
    public function the_list_costs_the_same_queries_for_3_or_30_players_at_risk(): void
    {
        $u15 = $this->category('U15');
        $s = $this->october($u15);
        $add = function (int $n) use ($u15, $s): void {
            foreach (range(1, $n) as $i) {
                $this->marks($this->player($u15), $s, ['AN', 'AN', 'AN', null, null, null]);
            }
        };
        $measure = function (): int {
            $risk = $this->risk();
            $risk->thresholds();   // loads the settings once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $risk->list(self::FROM, self::TO);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $add(3);
        $few = $measure();
        $add(27);

        $this->assertSame($few, $measure());
        // players(): 2, the streak scan: 1, the flagged players and their categories: 2.
        $this->assertSame(5, $few);
    }
}
