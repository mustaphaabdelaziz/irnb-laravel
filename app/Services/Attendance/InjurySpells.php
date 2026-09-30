<?php

namespace App\Services\Attendance;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\InjuryNote;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Injury spells, built from the marks. An injury mark is "present, not
 * training" (whatever the reason) or an excused absence for injury. A
 * player's consecutive injury marks in held sessions (date, start time, id),
 * with no other mark of theirs in between, form one spell. A spell is open
 * while it holds the player's latest held mark.
 *
 * Spells always come from the player's whole history, so a spell's start
 * never depends on the period on screen and an InjuryNote keyed by it stays
 * attached. A detail whose date no longer opens a spell (an earlier mark was
 * edited) is reported as unmatched, never dropped.
 */
final class InjurySpells
{
    public function __construct(private readonly PlayerNames $names) {}

    public static function isInjury(string $status, ?string $reason): bool
    {
        return $status === AttendanceStatus::NotTraining->value
            || ($status === AttendanceStatus::AbsentExcused->value && $reason === AbsenceReason::Injury->value);
    }

    /**
     * @param  iterable<object>  $marks  rows with player_id, date, status, reason, ordered by player, date, start time, id
     * @return array<int, list<array{start: string, end: string, sessions: int, open: bool}>> by player id, oldest first
     */
    public static function fromMarks(iterable $marks): array
    {
        $spells = [];
        $running = []; // player id => index of the spell the next injury mark extends
        foreach ($marks as $mark) {
            $id = (int) $mark->player_id;
            if (! self::isInjury($mark->status, $mark->reason)) {
                unset($running[$id]);

                continue;
            }
            if (! isset($running[$id])) {
                $spells[$id][] = ['start' => $mark->date, 'end' => $mark->date, 'sessions' => 0, 'open' => false];
                $running[$id] = array_key_last($spells[$id]);
            }
            $spells[$id][$running[$id]]['end'] = $mark->date;
            $spells[$id][$running[$id]]['sessions']++;
        }
        // A spell still running after the player's last mark holds that mark: it is open.
        foreach ($running as $id => $index) {
            $spells[$id][$index]['open'] = true;
        }

        return $spells;
    }

    /** @return list<array{start: string, end: string, sessions: int, open: bool}> the player's whole history, oldest first */
    public function all(int $playerId): array
    {
        return self::fromMarks($this->marks()->where('attendances.player_id', $playerId)->cursor())[$playerId] ?? [];
    }

    /**
     * The profile's injuries: the spells overlapping [from, to], newest first,
     * each with its detail (or null), and every detail of the player that
     * opens no spell. Two queries.
     *
     * @return array{spells: list<array<string, mixed>>, unmatched: list<array<string, mixed>>}
     */
    public function forPlayer(int $playerId, string $from, string $to): array
    {
        $spells = $this->all($playerId);
        $notes = InjuryNote::where('player_id', $playerId)->orderBy('start_date')->get();
        $byStart = $notes->keyBy('start_date');
        $starts = array_column($spells, 'start');

        $shown = [];
        foreach (array_reverse($spells) as $spell) {
            if (self::overlaps($spell, $from, $to)) {
                $shown[] = [...$spell, 'note' => $byStart->get($spell['start'])?->toDetail()];
            }
        }

        return [
            'spells' => $shown,
            'unmatched' => $notes->reject(fn (InjuryNote $note) => in_array($note->start_date, $starts, true))
                ->map(fn (InjuryNote $note) => $note->toDetail())->values()->all(),
        ];
    }

    /**
     * The club list. `current`: every open spell, oldest start first (today,
     * whatever the period). `spells`: the spells overlapping [from, to],
     * newest start first. Active players only (not archived, not left);
     * $categoryId keeps those now in that category. Four queries whatever
     * the roster: the marks of players with an injury mark, their details,
     * the players, their categories.
     *
     * @return array{current: list<array<string, mixed>>, spells: list<array<string, mixed>>}
     */
    public function club(string $from, string $to, ?int $categoryId = null): array
    {
        $byPlayer = self::fromMarks($this->marks()->whereIn('attendances.player_id', $this->injuredPlayers())->cursor());
        if ($byPlayer === []) {
            return ['current' => [], 'spells' => []];
        }
        $notes = InjuryNote::whereIn('player_id', array_keys($byPlayer))->get()
            ->keyBy(fn (InjuryNote $note) => $note->player_id.'|'.$note->start_date);

        $rows = [];
        foreach ($byPlayer as $playerId => $spells) {
            foreach ($spells as $spell) {
                if ($spell['open'] || self::overlaps($spell, $from, $to)) {
                    $rows[] = ['player_id' => $playerId, ...$spell, 'note' => $notes->get($playerId.'|'.$spell['start'])?->toDetail()];
                }
            }
        }
        $rows = array_values(array_filter(
            $this->names->attach($rows),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));

        $current = array_values(array_filter($rows, fn (array $row) => $row['open']));
        usort($current, fn (array $a, array $b) => [$a['start'], $a['name']] <=> [$b['start'], $b['name']]);
        $inPeriod = array_values(array_filter($rows, fn (array $row) => self::overlaps($row, $from, $to)));
        usort($inPeriod, fn (array $a, array $b) => [$b['start'], $a['name']] <=> [$a['start'], $b['name']]);

        return ['current' => $current, 'spells' => $inPeriod];
    }

    /** A spell overlaps [from, to] when it starts by $to and ends on $from or later; an open spell runs until today. */
    private static function overlaps(array $spell, string $from, string $to): bool
    {
        return $spell['start'] <= $to && ($spell['open'] || $spell['end'] >= $from);
    }

    /** Held-session marks in spell order: player, date, start time, session id. */
    private function marks(): Builder
    {
        return DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->orderBy('attendances.player_id')
            ->orderBy('training_sessions.date')
            ->orderBy('training_sessions.start_time')
            ->orderBy('training_sessions.id')
            ->select('attendances.player_id', 'attendances.status', 'attendances.reason', 'training_sessions.date');
    }

    /** Players with at least one injury mark in a held session (a subquery). */
    private function injuredPlayers(): Builder
    {
        return DB::table('attendances as a')
            ->join('training_sessions as s', 's.id', '=', 'a.training_session_id')
            ->where('s.state', SessionState::Held->value)
            ->where(fn (Builder $q) => $q->where('a.status', AttendanceStatus::NotTraining->value)
                ->orWhere(fn (Builder $q) => $q->where('a.status', AttendanceStatus::AbsentExcused->value)
                    ->where('a.reason', AbsenceReason::Injury->value)))
            ->select('a.player_id');
    }
}
