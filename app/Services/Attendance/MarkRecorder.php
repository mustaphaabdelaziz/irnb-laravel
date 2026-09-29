<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a session's full set of marks: the given players are the roster
 * (anyone missing is removed), fields a status does not take are cleared,
 * and the session becomes held.
 */
final class MarkRecorder
{
    private const LOG_FIELDS = ['coach', 'theme', 'notes'];

    public function save(TrainingSession $session, array $marks, ?User $user, ?array $log = null): int
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['session' => 'att.error.cancelled']);
        }

        if ($marks === []) {
            throw ValidationException::withMessages(['marks' => 'att.error.no_players']);
        }

        return DB::transaction(function () use ($session, $marks, $user, $log) {
            $playerIds = [];

            foreach ($marks as $mark) {
                $status = AttendanceStatus::from($mark['status']);
                $playerIds[] = (int) $mark['player_id'];

                Attendance::updateOrCreate(
                    ['training_session_id' => $session->id, 'player_id' => (int) $mark['player_id']],
                    [
                        'status' => $status,
                        'minutes' => $status->takesMinutes() ? (int) ($mark['minutes'] ?? 0) : null,
                        'reason' => $status->takesReason() ? ($mark['reason'] ?? null) : null,
                        'note' => ($mark['note'] ?? null) ?: null,
                        'recorded_by' => $user?->id,
                    ],
                );
            }

            $session->attendances()->whereNotIn('player_id', $playerIds)->delete();

            $session->state = SessionState::Held;
            if ($log !== null) {
                $session->fill(array_intersect_key($log, array_flip(self::LOG_FIELDS)));
            }
            $session->save();

            ActivityRecorder::record($user, ActivityAction::ATTENDANCE_MARKED, $session, ['count' => count($playerIds)]);

            return count($playerIds);
        });
    }
}
