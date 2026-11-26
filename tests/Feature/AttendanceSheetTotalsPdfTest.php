<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceSheetTotalsPdfTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function markAs(TrainingSession $training, Player $player, string $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $training->category_id, 'status' => $status]);
    }

    private function monthHtml(Category $category, array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.month', ['category_id' => $category->id, 'month' => '2026-10'] + $query))
            ->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_filled_month_sheet_prints_present_absent_and_rate_per_player(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $s1 = $this->training($u15, '2026-10-05');
        $s2 = $this->training($u15, '2026-10-12');
        $this->markAs($s1, $a, 'present');
        $this->markAs($s2, $a, 'absent_unexcused');

        $html = $this->monthHtml($u15, ['filled' => 1]);

        $this->assertSame(3, substr_count($html, '<th class="total"'));
        $this->assertStringContainsString('<td class="total">1</td>', $html);
        $this->assertStringContainsString('50.0%', $html);
    }

    #[Test]
    public function the_blank_month_sheet_has_no_totals(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        $this->training($u15, '2026-10-05');

        $this->assertStringNotContainsString('class="total"', $this->monthHtml($u15));
    }

    private function sessionHtml(TrainingSession $training, array $query = []): string
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.session', ['session' => $training->id] + $query))
            ->assertOk();

        return $seen['html'];
    }

    #[Test]
    public function the_filled_session_sheet_prints_a_summary_line(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $b = $this->player($u15);
        $c = $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $this->markAs($training, $a, 'present');
        $this->markAs($training, $b, 'late');
        $this->markAs($training, $c, 'absent_excused');

        $html = $this->sessionHtml($training, ['filled' => 1]);

        $this->assertStringContainsString('class="summary"', $html);
        $this->assertStringContainsString('Présents : 2 · Absents : 1', $html);
        $this->assertStringContainsString('66.7%', $html);
    }

    #[Test]
    public function the_blank_session_sheet_has_no_summary_line(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $training = $this->training($u15, '2026-10-05');
        $this->markAs($training, $a, 'present');

        $this->assertStringNotContainsString('class="summary"', $this->sessionHtml($training));
    }

    #[Test]
    public function with_two_parts_the_totals_are_on_the_last_part_only(): void
    {
        $u15 = $this->category('U15');
        $this->player($u15);
        for ($day = 1; $day <= 17; $day++) {
            $this->training($u15, sprintf('2026-10-%02d', $day));
        }

        $this->assertSame(3, substr_count($this->monthHtml($u15, ['filled' => 1]), '<th class="total"'));
    }
}
