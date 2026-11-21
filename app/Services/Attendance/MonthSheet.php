<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One category's month (of one branch, when given) as players × sessions, shared by the month grid and
 * the printed month sheet so paper and screen always list the same people.
 * A cell exists only where the player is on that session's list: the frozen
 * marks once saved, else the expected roster on the session's date. A joint
 * pre-season session brings its whole roster, since saving its column
 * replaces all of its marks.
 *
 * A category split into training groups is shown one group at a time
 * (groupSwitch() lists the choices, build() keeps the chosen one's sessions).
 */
final class MonthSheet
{
    // Roster::COLUMNS: what Player::fullname and the sheets need.
    private const PLAYER_COLUMNS = Roster::COLUMNS;

    /** The switch entry for the sessions with no group, in a category that has groups («whole category»). */
    public const NO_GROUP = 0;

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly Roster $roster,
    ) {}

    /**
     * The group switch of the month grid and the printed month sheet: one entry
     * per training group of the branch + category (by name), plus NO_GROUP when
     * the month has sessions with no group. `selected` is $requested when it is
     * one of the entries, else the first group. A category without groups has
     * no switch: no options, nothing selected, and build() shows every session.
     *
     * @return array{options: list<array{id: int, name: ?string}>, selected: ?int}
     */
    public function groupSwitch(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $requested = null): array
    {
        $groups = TrainingGroup::where('branch_id', $branchId) // null → whereNull
            ->where('category_id', $categoryId)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
        if ($groups->isEmpty()) {
            return ['options' => [], 'selected' => null];
        }

        // Generated first, like build(): a month nobody opened yet has no session to look at.
        $this->generator->forMonth($categoryId, $year, $month);

        $options = $groups->toBase()->map(fn (TrainingGroup $group) => ['id' => $group->id, 'name' => $group->name])->values()->all();
        if ($this->monthSessions($categoryId, $year, $month, $branchId)->whereNull('group_id')->exists()) {
            $options[] = ['id' => self::NO_GROUP, 'name' => null];
        }
        $ids = array_column($options, 'id');

        return ['options' => $options, 'selected' => in_array($requested, $ids, true) ? $requested : $ids[0]];
    }

    /**
     * Generates the month first (idempotent), like opening it on the calendar.
     * $branchId null = every branch's sessions (a club without branches).
     * $groupId null = every session; NO_GROUP = only the sessions with no group;
     * a training group's id = only its sessions.
     *
     * @return array{sessions: Collection<int, TrainingSession>, cells: array<int, array<int, string>>, players: Collection<int, Player>}
     */
    public function build(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $groupId = null): array
    {
        $this->generator->forMonth($categoryId, $year, $month);

        $sessions = $this->monthSessions($categoryId, $year, $month, $branchId)
            ->when($groupId === self::NO_GROUP, fn ($q) => $q->whereNull('group_id'))
            ->when($groupId !== null && $groupId !== self::NO_GROUP, fn ($q) => $q->where('group_id', $groupId))
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
            $statusIds = $this->roster->statusIdsFor($session);
            $key = $session->branch_id.'|'.$session->group_id.'|'.$session->date.'|'.implode(',', $categoryIds).'|'.implode(',', $statusIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date, $statusIds, $session->branch_id, $session->group_id)->modelKeys();
            foreach ($expected[$key] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $players = Player::whereIn('id', array_keys($cells))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::PLAYER_COLUMNS);

        return ['sessions' => $sessions, 'cells' => $cells, 'players' => $players];
    }

    /** The category's sessions of the month that are not cancelled (of one branch, when given). */
    private function monthSessions(int $categoryId, int $year, int $month, ?int $branchId): Builder
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return TrainingSession::includingCategory($categoryId)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()]);
    }
}
