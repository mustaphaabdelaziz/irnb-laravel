<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\AttendanceCustomStatus;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\InjurySpells;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * Custom codes count as their behaviour: present / absent_excused /
 * absent_unexcused like those statuses, not_counted leaves the session out
 * for that player. Each still shows as its own count.
 */
class AttendanceCustomStatusStatsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private int $day = 0;

    private function custom(string $code, string $behaviour): string
    {
        return AttendanceCustomStatus::createWithKey([
            'code' => $code, 'color' => '#7c3aed', 'label_fr' => $code, 'behaviour' => $behaviour, 'is_active' => true,
        ])->key;
    }

    /** One held 90-minute session per call, on consecutive October days, with $player marked $status. */
    private function mark(Category $category, Player $player, string $status): void
    {
        $session = TrainingSession::create([
            'category_id' => $category->id, 'date' => sprintf('2026-10-%02d', ++$this->day), 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $player->id, 'category_id' => $category->id, 'status' => $status]);
    }

    #[Test]
    public function custom_marks_count_as_their_behaviour_and_not_counted_ones_are_left_out(): void
    {
        $travel = $this->custom('V', 'not_counted');
        $here = $this->custom('H', 'present');
        $skipped = $this->custom('S', 'absent_unexcused');
        $u15 = $this->category();
        $player = $this->player($u15);
        foreach (['present', 'present', 'present', $travel, $travel, $here, $skipped] as $status) {
            $this->mark($u15, $player, $status);
        }

        $row = app(AttendanceStats::class)->players('2026-10-01', '2026-10-31')[$player->id];

        $this->assertSame(3, $row['counts']['present']);
        $this->assertSame(2, $row['counts'][$travel]);
        $this->assertSame(1, $row['counts'][$here]);
        $this->assertSame(1, $row['counts'][$skipped]);
        $this->assertSame(4, $row['scored']['present']);
        $this->assertSame(1, $row['scored']['absent_unexcused']);
        $this->assertSame(5, $row['expected']);            // the two travelling sessions are left out
        $this->assertSame(3.0, $row['score']);              // 4 × 1 + 1 × -1
        $this->assertSame(60.0, $row['score_pct']);
        $this->assertNull($row['pct'][$travel]);
        $this->assertSame(20.0, $row['pct'][$here]);
        $this->assertSame(90, $row['missed_minutes']);      // the custom unexcused absence

        $totals = app(AttendanceStats::class)->summarize([$row]);
        $this->assertSame(5, $totals['expected']);
        $this->assertSame(2, $totals['counts'][$travel]);

        $monthly = app(AttendanceStats::class)->monthly('2026-10-01', '2026-10-31');
        $this->assertSame([2], $monthly['statuses'][$travel]);
    }

    #[Test]
    public function a_custom_unexcused_absence_extends_a_streak_and_a_not_counted_session_is_skipped(): void
    {
        $travel = $this->custom('V', 'not_counted');
        $skipped = $this->custom('S', 'absent_unexcused');
        $u15 = $this->category();
        $player = $this->player($u15);
        foreach (['absent_unexcused', $travel, $skipped, $skipped, 'present', $skipped] as $status) {
            $this->mark($u15, $player, $status);
        }

        $streak = app(AttendanceStats::class)->unexcusedStreaks('2026-10-01', '2026-10-31')[$player->id];

        $this->assertSame(3, $streak['longest']);
        $this->assertSame(1, $streak['current']);

        $risky = collect(app(AtRisk::class)->list('2026-10-01', '2026-10-31'))->firstWhere('player_id', $player->id);
        $this->assertSame(4, $risky['unexcused']);
        $this->assertTrue($risky['streak']);
    }

    #[Test]
    public function a_player_only_marked_not_counted_is_expected_nowhere(): void
    {
        $travel = $this->custom('V', 'not_counted');
        $u15 = $this->category();
        $player = $this->player($u15);
        foreach ([$travel, $travel, $travel, $travel, $travel] as $status) {
            $this->mark($u15, $player, $status);
        }

        $row = app(AttendanceStats::class)->players('2026-10-01', '2026-10-31')[$player->id];

        $this->assertSame(0, $row['expected']);
        $this->assertNull($row['score_pct']);
        $this->assertSame([], AttendanceStats::ranked([$row]));
    }

    #[Test]
    public function the_statistics_export_has_a_column_per_custom_code(): void
    {
        $travel = $this->custom('V', 'not_counted');
        AttendanceCustomStatus::where('key', $travel)->update(['label_en' => 'Travelling']);
        $u15 = $this->category();
        $player = $this->player($u15);
        $this->mark($u15, $player, $travel);
        $this->mark($u15, $player, 'present');

        $csv = $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'en']))
            ->get(route('attendance.stats.export', ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Travelling', $csv);
        $this->assertStringContainsString('Travelling %', $csv);
    }

    #[Test]
    public function the_parent_letter_lists_custom_absences_and_counts_them_as_absences(): void
    {
        $skipped = $this->custom('S', 'absent_unexcused');
        $travel = $this->custom('V', 'not_counted');
        AttendanceCustomStatus::where('key', $skipped)->update(['label_en' => 'Skipped']);
        AttendanceSettings::save(['letter' => ['body' => ['en' => 'Absences: {absences}.']]]);
        $u15 = $this->category();
        $player = $this->player($u15);
        foreach ([$skipped, $skipped, 'absent_excused', $travel, 'present'] as $status) {
            $this->mark($u15, $player, $status);
        }
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'en']))
            ->get(route('attendance.players.letter', ['player' => $player, 'period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk();

        $this->assertStringContainsString('Absences: 3.', $seen['html']);
        $this->assertStringContainsString('Skipped', $seen['html']);
    }

    #[Test]
    public function a_not_counted_mark_neither_ends_nor_extends_an_injury_spell(): void
    {
        $travel = $this->custom('V', 'not_counted');
        $u15 = $this->category();
        $closed = $this->player($u15);
        $open = $this->player($u15);
        foreach (['not_training', $travel, 'not_training', 'present'] as $status) {
            $this->mark($u15, $closed, $status);
        }
        foreach (['present', 'not_training', $travel] as $status) {
            $this->mark($u15, $open, $status);
        }
        $spells = app(InjurySpells::class);

        $this->assertSame([['start' => '2026-10-01', 'end' => '2026-10-03', 'sessions' => 2, 'open' => false]], $spells->all($closed->id));
        $this->assertSame([['start' => '2026-10-06', 'end' => '2026-10-06', 'sessions' => 1, 'open' => true]], $spells->all($open->id));
        $current = app(InjurySpells::class)->club('2026-10-01', '2026-10-31')['current'];
        $this->assertSame([$open->id], array_column($current, 'player_id'));
    }

    #[Test]
    public function a_hidden_code_gets_a_column_only_while_it_has_marks(): void
    {
        $unused = $this->custom('Q', 'present');
        $used = $this->custom('W', 'present');
        AttendanceCustomStatus::whereIn('key', [$unused, $used])->update(['is_active' => false]);
        AttendanceCustomStatus::where('key', $unused)->update(['label_en' => 'Quiet']);
        AttendanceCustomStatus::where('key', $used)->update(['label_en' => 'Worked']);
        $u15 = $this->category();
        $this->mark($u15, $this->player($u15), $used);

        $csv = $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'en']))
            ->get(route('attendance.stats.export', ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('Worked', $csv);
        $this->assertStringNotContainsString('Quiet', $csv);
    }
}
