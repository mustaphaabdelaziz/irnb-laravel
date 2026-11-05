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

/** Sessions and marks for the attendance tests that use RegistrationFixtures. */
trait RegistrationSessions
{
    /** @param  list<Category>  $categories  the first is the primary; more make a joint session */
    private function sessionIn(?Branch $branch, array $categories, string $date = '2026-03-10', array $extra = []): TrainingSession
    {
        $session = TrainingSession::create($extra + [
            'branch_id' => $branch?->id, 'category_id' => $categories[0]->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => count($categories) > 1 ? SessionKind::Preseason : SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        if (count($categories) > 1) {
            $session->categories()->syncWithoutDetaching(array_map(fn (Category $c) => $c->id, $categories));
        }

        return $session;
    }

    private function markOn(TrainingSession $session, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        Attendance::create([
            'training_session_id' => $session->id, 'player_id' => $player->id, 'category_id' => $session->category_id,
            'status' => $status, 'reason' => $reason,
        ]);
    }
}
