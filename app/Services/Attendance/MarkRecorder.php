<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a session's full set of marks: the given players are the roster
 * (anyone missing is removed), fields a status does not take are cleared,
 * and the session becomes held.
 */
final class MarkRecorder
{
    private const LOG_FIELDS = ['coach', 'title', 'notes'];

    public function __construct(private readonly AttendanceStatusCatalog $catalog) {}

    public function save(TrainingSession $session, array $marks, ?User $user, ?array $log = null): int
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['session' => 'att.error.cancelled']);
        }

        if ($marks === []) {
            throw ValidationException::withMessages(['marks' => 'att.error.no_players']);
        }

        return DB::transaction(function () use ($session, $marks, $user, $log) {
            $playerIds = array_map(fn (array $mark) => (int) $mark['player_id'], $marks);

            $existingPlayerIds = $session->attendances()->whereIn('player_id', $playerIds)->pluck('player_id')
                ->map(fn ($id) => (int) $id)->all();
            // A new mark takes the player's category in the session's branch, but an
            // existing mark keeps whatever category_id it already has (see the loop
            // below) — later roster moves never rewrite it.
            $categoryByPlayer = $this->newMarkCategories($session, array_diff($playerIds, $existingPlayerIds));

            foreach ($marks as $mark) {
                $status = (string) $mark['status'];
                if (! $this->catalog->has($status)) {
                    throw ValidationException::withMessages(['marks' => 'att.error.invalid_code']);
                }
                $playerId = (int) $mark['player_id'];

                $values = [
                    'status' => $status,
                    'minutes' => $this->catalog->takesMinutes($status) ? (int) ($mark['minutes'] ?? 0) : null,
                    'reason' => $this->catalog->takesReason($status) ? ($mark['reason'] ?? null) : null,
                    'note' => ($mark['note'] ?? null) ?: null,
                    'recorded_by' => $user?->id,
                ];
                if (! in_array($playerId, $existingPlayerIds, true)) {
                    $values['category_id'] = $categoryByPlayer[$playerId] ?? null;
                }

                Attendance::updateOrCreate(
                    ['training_session_id' => $session->id, 'player_id' => $playerId],
                    $values,
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

    /**
     * Each player's category in the session's branch; a player added by hand
     * with no registration there, or a session with no branch, takes the main one.
     *
     * @param  array<int, int>  $playerIds
     * @return array<int, int|null> by player id
     */
    private function newMarkCategories(TrainingSession $session, array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }
        $display = RegistrationDisplay::make();

        return Player::with('registrations')->whereIn('id', $playerIds)->get(['id', 'category_id'])
            ->mapWithKeys(fn (Player $player) => [$player->id => $display->values($player, $session->branch_id)['category_id']])
            ->all();
    }
}
