<?php

namespace Tests\Support;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Pdf\PdfService;
use Mockery\MockInterface;

/**
 * A two-sport club for the per-branch report tests (use with
 * AttendanceFixtures). Branches are created before any session: the club's
 * first branch adopts club-wide rows (BranchObserver).
 */
trait BranchAttendanceFixtures
{
    /** @return array{0: Branch, 1: Branch} football (lower id), swimming */
    private function twoSports(): array
    {
        return [Branch::create(['name' => 'Football']), Branch::create(['name' => 'Swimming'])];
    }

    private function branchSession(Branch $branch, Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra));
    }

    private function branchMark(TrainingSession $training, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $player->category_id,
            'status' => $status, 'reason' => $reason,
        ]);
    }

    /**
     * U15, five Mondays (2026-09-07 → 10-05), each with a football and a
     * swimming session at 18:00 (football created first, so it sorts first).
     * "both" (member of both): football AN, AN, AN, P, P (0 %, streak 3);
     * swimming P × 5 (100 %). "footer" (football only): P × 5. "swimmer"
     * (swimming only): P × 5. Mixed together, "both" would be 10 expected,
     * 40 %, longest streak 1 (each swimming mark breaks it).
     *
     * @return array{foot: Branch, swim: Branch, u15: Category, both: Player, footer: Player, swimmer: Player}
     */
    private function twoSportClub(): array
    {
        [$foot, $swim] = $this->twoSports();
        $u15 = $this->category('U15');
        $both = $this->player($u15);
        $footer = $this->player($u15);
        $swimmer = $this->player($u15);
        $foot->players()->attach([$both->id, $footer->id]);
        $swim->players()->attach([$both->id, $swimmer->id]);
        foreach (['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'] as $i => $date) {
            $football = $this->branchSession($foot, $u15, $date);
            $swimming = $this->branchSession($swim, $u15, $date);
            $this->branchMark($football, $both, $i < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present);
            $this->branchMark($football, $footer, AttendanceStatus::Present);
            $this->branchMark($swimming, $both, AttendanceStatus::Present);
            $this->branchMark($swimming, $swimmer, AttendanceStatus::Present);
        }

        return compact('foot', 'swim', 'u15', 'both', 'footer', 'swimmer');
    }

    /**
     * U15 "both" plays football and swims. Football: 10-01 P, 10-05 not
     * training (injury), 10-12 excused (injury) — the latest football mark,
     * so the football spell 10-05 → 10-12 is open. Swimming: 10-08 P, 10-14 P.
     * Mixed together the swimming marks would cut it into two closed spells.
     *
     * @return array{foot: Branch, swim: Branch, u15: Category, both: Player}
     */
    private function injuredFootballer(): array
    {
        [$foot, $swim] = $this->twoSports();
        $u15 = $this->category('U15');
        $both = $this->player($u15);
        $foot->players()->attach($both->id);
        $swim->players()->attach($both->id);
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-01'), $both, AttendanceStatus::Present);
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-05'), $both, AttendanceStatus::NotTraining, 'injury');
        $this->branchMark($this->branchSession($swim, $u15, '2026-10-08'), $both, AttendanceStatus::Present);
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $both, AttendanceStatus::AbsentExcused, 'injury');
        $this->branchMark($this->branchSession($swim, $u15, '2026-10-14'), $both, AttendanceStatus::Present);

        return compact('foot', 'swim', 'u15', 'both');
    }

    /** Swaps mPDF for a spy; returns a reference filled with what stream() received. */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }
}
