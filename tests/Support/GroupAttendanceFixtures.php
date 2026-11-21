<?php

namespace Tests\Support;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Services\Attendance\GroupMembership;

/**
 * A category split into two training groups, for the tests of the reports'
 * group filter (use with AttendanceFixtures and BranchAttendanceFixtures).
 */
trait GroupAttendanceFixtures
{
    /**
     * Swimming · Minime in two groups, season 2026/27.
     *
     * Ten Saturdays (2026-09-05 → 11-07): G1 at 09:30–11:00 (90 min), then G2
     * at 11:00–13:00 (120 min). Two Tuesdays (09-08, 09-15) at 17:00–18:30 for
     * the whole category (no group). One cancelled G2 session on 2026-11-14.
     *
     * "a" (G1): present at everything.
     * "b" (G2): unexcused on the first three Saturdays, then present.
     * "mover" (G1, then G2 from 2026-10-10): the first five Saturdays with G1
     * (unexcused × 3, present × 2), the last five with G2 (present × 5).
     * All three are present on both Tuesdays, which break every streak when
     * the groups are read together.
     *
     * G1: a 10 expected, 100 %; mover 5 expected, 0 %, streak 3, 270 min missed.
     * G2: b 10 expected, 40 %, streak 3, 360 min missed; mover 5 expected, 100 %.
     * All groups: 12 expected each; a 100 %, b 50 %, mover 50 %; longest streak 1.
     *
     * @return array{swim: Branch, minime: Category, g1: TrainingGroup, g2: TrainingGroup, a: Player, b: Player, mover: Player}
     */
    private function splitCategory(): array
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $g1 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $g2 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G2']);

        [$a, $b, $mover] = [$this->player($minime), $this->player($minime), $this->player($minime)];
        foreach ([$a, $b, $mover] as $player) {
            $swim->players()->attach($player->id, ['membership_id' => $player->membership_id]);
        }

        $membership = app(GroupMembership::class);
        $membership->add($g1, $a->id, null);
        $membership->add($g2, $b->id, null);
        $membership->add($g1, $mover->id, null);
        $membership->add($g2, $mover->id, '2026-10-10');

        $saturdays = ['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03', '2026-10-10', '2026-10-17', '2026-10-24', '2026-10-31', '2026-11-07'];
        foreach ($saturdays as $i => $date) {
            $first = $this->branchSession($swim, $minime, $date, ['group_id' => $g1->id, 'start_time' => '09:30', 'end_time' => '11:00']);
            $second = $this->branchSession($swim, $minime, $date, ['group_id' => $g2->id, 'start_time' => '11:00', 'end_time' => '13:00']);
            $earlyMiss = $i < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present;
            $this->branchMark($first, $a, AttendanceStatus::Present);
            $this->branchMark($second, $b, $earlyMiss);
            $this->branchMark($i < 5 ? $first : $second, $mover, $earlyMiss);
        }

        foreach (['2026-09-08', '2026-09-15'] as $date) {
            $plain = $this->branchSession($swim, $minime, $date, ['start_time' => '17:00', 'end_time' => '18:30']);
            foreach ([$a, $b, $mover] as $player) {
                $this->branchMark($plain, $player, AttendanceStatus::Present);
            }
        }

        $this->branchSession($swim, $minime, '2026-11-14', [
            'group_id' => $g2->id, 'start_time' => '11:00', 'end_time' => '13:00',
            'state' => SessionState::Cancelled, 'cancel_reason' => 'Pool closed',
        ]);

        return compact('swim', 'minime', 'g1', 'g2', 'a', 'b', 'mover');
    }
}
