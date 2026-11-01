<?php

namespace Tests\Feature\LiteraryReport;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\LiteraryReport\Sections\AttendanceSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSectionTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private function build(): array
    {
        return app(AttendanceSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function a_session_held_on_the_last_day_is_included(): void
    {
        $u15 = $this->category('U15');
        foreach (['2026-08-31', '2025-09-01', '2026-09-01'] as $date) {
            TrainingSession::create([
                'category_id' => $u15->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
                'kind' => SessionKind::Regular, 'state' => SessionState::Held,
            ]);
        }

        $this->assertSame(2, $this->build()['held']);
    }

    #[Test]
    public function it_counts_held_sessions_by_branch_and_the_attendance_rate_by_category(): void
    {
        $u15 = $this->category('U15');
        $football = Branch::create(['name' => 'Football']);
        $a = $this->player($u15);
        $b = $this->player($u15);
        $c = $this->player($u15);
        $session = fn (array $x) => TrainingSession::create($x + [
            'category_id' => $u15->id, 'date' => '2026-03-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'branch_id' => $football->id,
        ]);
        $held = $session([]);
        $session(['state' => SessionState::Cancelled, 'start_time' => '10:00', 'end_time' => '11:00']);
        $session(['date' => '2025-08-01']); // before the period
        // A late arrival attended (owner decision): 2 of 3 expected marks count.
        foreach ([[$a, 'present'], [$b, 'absent_unexcused'], [$c, 'late']] as [$player, $status]) {
            Attendance::create(['training_session_id' => $held->id, 'player_id' => $player->id, 'status' => $status, 'category_id' => $u15->id]);
        }

        $data = $this->build();

        $this->assertSame(1, $data['held']);
        $this->assertSame([['name' => 'Football', 'held' => 1, 'rate' => 66.7]], $data['branches']);
        $this->assertSame([['name' => 'U15', 'held' => 1, 'expected' => 3, 'rate' => 66.7]], $data['categories']);
    }

    #[Test]
    public function sessions_without_a_branch_are_listed_as_club_wide(): void
    {
        $u15 = $this->category('U15');
        TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-03-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);

        $data = $this->build();

        $this->assertSame([['name' => null, 'held' => 1, 'rate' => null]], $data['branches']);
    }

    #[Test]
    public function an_empty_period_has_no_rows(): void
    {
        $data = $this->build();
        $this->assertSame(0, $data['held']);
        $this->assertSame([], $data['categories']);
    }
}
