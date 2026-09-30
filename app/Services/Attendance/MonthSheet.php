<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Player;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One category's month as players × sessions, shared by the month grid and
 * the printed month sheet so paper and screen always list the same people.
 * A cell exists only where the player is on that session's list: the frozen
 * marks once saved, else the expected roster on the session's date. A joint
 * pre-season session brings its whole roster, since saving its column
 * replaces all of its marks.
 */
final class MonthSheet
{
    private const PLAYER_COLUMNS = ['id', 'firstname', 'lastname', 'file_number', 'category_id'];

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly Roster $roster,
    ) {}

    /**
     * Generates the month first (idempotent), like opening it on the calendar.
     *
     * @return array{sessions: Collection<int, TrainingSession>, cells: array<int, array<int, string>>, players: Collection<int, Player>}
     */
    public function build(int $categoryId, int $year, int $month): array
    {
        $this->generator->forMonth($categoryId, $year, $month);
        $first = CarbonImmutable::create($year, $month, 1);

        $sessions = TrainingSession::includingCategory($categoryId)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()])
            ->with(['attendances', 'categories'])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $codes = AttendanceCode::fromSettings();
        $cells = [];
        $expected = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = $codes->format($mark->status, $mark->minutes);
                }

                continue;
            }
            $categoryIds = $session->categoryIds();
            $key = $session->date.'|'.implode(',', $categoryIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date)->modelKeys();
            foreach ($expected[$key] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $players = Player::whereIn('id', array_keys($cells))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::PLAYER_COLUMNS);

        return ['sessions' => $sessions, 'cells' => $cells, 'players' => $players];
    }
}
