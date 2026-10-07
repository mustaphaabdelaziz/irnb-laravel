<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceMonthSheetTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        // $extra must win over these defaults (e.g. overriding state to cancelled): put it first, since + keeps the left array's value on key collisions.
        return TrainingSession::create($extra + [
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status = AttendanceStatus::Present, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
        $training->update(['state' => SessionState::Held]);
    }

    #[Test]
    public function it_generates_the_month_and_leaves_out_cancelled_sessions(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        $cancelled = $this->training($u15, '2026-10-07', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $sheet = app(MonthSheet::class)->build($u15->id, 2026, 10);

        // October 2026: Mondays 5, 12, 19, 26.
        $this->assertSame(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], $sheet['sessions']->pluck('date')->all());
        $this->assertNotContains($cancelled->id, $sheet['sessions']->modelKeys());
        $this->assertSame([$a->id], $sheet['players']->modelKeys());
        $this->assertSame(array_fill_keys($sheet['sessions']->modelKeys(), ''), $sheet['cells'][$a->id]);
    }

    #[Test]
    public function cells_follow_the_expected_roster_before_marking_and_the_frozen_one_after(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $left = Player::leftStatusId();
        $stays = $this->player($u15);
        $this->player($u15, ['archived' => true]);
        $this->player($u15, ['status_id' => $left, 'left_at' => '2026-09-20']);
        $leftMid = $this->player($u15, ['status_id' => $left, 'left_at' => '2026-10-10']);
        $moved = $this->player($u15);
        $held = $this->training($u15, '2026-10-05');
        $this->mark($held, $stays);
        $this->mark($held, $moved, AttendanceStatus::Late, 15);
        $moved->update(['category_id' => $u17->id]);
        $newcomer = $this->player($u15);
        $early = $this->training($u15, '2026-10-07');
        $planned = $this->training($u15, '2026-10-12');

        $sheet = app(MonthSheet::class)->build($u15->id, 2026, 10);

        $this->assertSame([$stays->id, $leftMid->id, $moved->id, $newcomer->id], $sheet['players']->modelKeys());
        $this->assertSame([$held->id => 'P', $early->id => '', $planned->id => ''], $sheet['cells'][$stays->id]);
        $this->assertSame([$early->id => ''], $sheet['cells'][$leftMid->id]);
        $this->assertSame([$held->id => 'R15'], $sheet['cells'][$moved->id]);
        $this->assertSame([$early->id => '', $planned->id => ''], $sheet['cells'][$newcomer->id]);
    }

    #[Test]
    public function a_joint_session_brings_its_whole_roster_into_each_category(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $joint = $this->training($u17, '2026-10-08', ['kind' => SessionKind::Preseason]);
        $joint->categories()->syncWithoutDetaching([$u15->id]);
        $this->training($u17, '2026-10-09');

        $sheet = app(MonthSheet::class)->build($u15->id, 2026, 10);

        $this->assertSame([$joint->id], $sheet['sessions']->modelKeys());
        $this->assertSame([$a->id, $b->id], $sheet['players']->modelKeys());
    }
}
