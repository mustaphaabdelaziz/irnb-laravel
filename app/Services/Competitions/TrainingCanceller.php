<?php

namespace App\Services\Competitions;

use App\Enums\SessionState;
use App\Models\Game;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Attendance\SessionGenerator;
use App\Support\UiLang;

/**
 * On a match day the team usually does not train. Cancels the planned
 * sessions of the team's branch and category on the match date (a football
 * match leaves swimming alone), giving the match as the reason, exactly as a coach would cancel them on the attendance page.
 */
final class TrainingCanceller
{
    public function __construct(private readonly SessionGenerator $generator) {}

    public function cancelForGame(Game $game, User $user): int
    {
        $game->loadMissing(['team', 'opponent']);
        $categoryId = $game->team->category_id;
        [$year, $month] = array_map('intval', explode('-', substr($game->date, 0, 7)));

        // Regular sessions are generated lazily per month: make sure that day's exist first.
        $this->generator->forMonth($categoryId, $year, $month);

        $reason = mb_substr(strtr(UiLang::get('comp.training_cancelled_reason'), ['{opponent}' => $game->opponent->name]), 0, 255);
        $sessions = TrainingSession::query()
            ->where('category_id', $categoryId)->where('date', $game->date)->where('state', SessionState::Planned->value)
            ->when($game->team->branch_id !== null, fn ($q) => $q->where('branch_id', $game->team->branch_id))
            ->get();

        foreach ($sessions as $session) {
            $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => $reason]);
            ActivityRecorder::record($user, ActivityAction::TRAINING_SESSION_CANCELLED, $session);
        }

        return $sessions->count();
    }
}
