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
 *
 * A spell built as open (it holds the player's latest held mark) closes
 * early when its detail's `returned_on` is on or after the spell's end (the
 * latest mark's date) and no later than today: `open` becomes false and
 * `end` moves to `returned_on` — so overlaps() and the club's current list
 * treat the return date as where the spell actually stops. A `returned_on`
 * still in the future is a planned return, not yet a close: the spell stays
 * open and `returned_on` is exposed so the UI can show "expected back". A
 * later injury mark that extends the spell past a (past) `returned_on`
 * reopens it: `end` then runs past `returned_on` again, so "on or after" no
 * longer holds — see withReturn(). A spell already closed by later marks
 * (never by the return note itself) exposes `returned_on` only when it is
 * on or after the spell's end; an earlier, stale return date predates marks
 * that show the player still injured, so it is not this spell's return and
 * is not exposed.
 *
 * The club list scans only the players who can matter to it, then builds
 * their spells from their whole history as above. A spell shown is open
 * (then the player's latest held mark is an injury mark) or overlaps
 * [from, to]; an overlapping closed spell either has an injury mark dated in
 * [from, to] (this covers one begun before $from and ended inside the
 * period), or spans the period with no mark of the player in it (then their
 * latest held mark before $from is an injury mark). So the scanned players
 * are the union of: (a) an injury mark dated in [from, to]; (b) the latest
 * held mark is an injury mark; (c) the latest held mark before $from is an
 * injury mark. A player with only an old closed spell is not read.
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
     * A spell with its detail's return date applied: closes an open spell
     * whose `returned_on` is on or after its end (moving `end` to it) and no
     * later than today — a future `returned_on` is a planned return, so it
     * keeps the spell open instead (still exposed, for "expected back"). A
     * spell that is not open was closed by later marks, not by this note: it
     * exposes `returned_on` only when it is on or after its end, never a
     * stale date that predates marks showing the player still injured.
     *
     * @param  array{start: string, end: string, sessions: int, open: bool}  $spell
     */
    private static function withReturn(array $spell, ?string $returnedOn): array
    {
        if ($spell['open']) {
            if ($returnedOn !== null && $returnedOn >= $spell['end'] && $returnedOn <= now()->toDateString()) {
                return [...$spell, 'open' => false, 'end' => $returnedOn, 'returned_on' => $returnedOn];
            }

            return [...$spell, 'returned_on' => $returnedOn !== null && $returnedOn >= $spell['end'] ? $returnedOn : null];
        }

        return [...$spell, 'returned_on' => $returnedOn !== null && $returnedOn >= $spell['end'] ? $returnedOn : null];
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
            $note = $byStart->get($spell['start']);
            $spell = self::withReturn($spell, $note?->returned_on);
            if (self::overlaps($spell, $from, $to)) {
                $shown[] = [...$spell, 'note' => $note?->toDetail()];
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
     * the roster: the marks of the players who can matter (see the class
     * doc), their details, the players, their categories.
     *
     * @return array{current: list<array<string, mixed>>, spells: list<array<string, mixed>>}
     */
    public function club(string $from, string $to, ?int $categoryId = null): array
    {
        $byPlayer = self::fromMarks($this->marks()->where(fn (Builder $q) => $q
            ->whereIn('attendances.player_id', $this->injuryMarks()->whereBetween('s.date', [$from, $to]))
            ->orWhereIn('attendances.player_id', $this->latestMarkIsInjury())
            ->orWhereIn('attendances.player_id', $this->latestMarkIsInjury($from))
        )->cursor());
        if ($byPlayer === []) {
            return ['current' => [], 'spells' => []];
        }
        $notes = InjuryNote::whereIn('player_id', array_keys($byPlayer))->get()
            ->keyBy(fn (InjuryNote $note) => $note->player_id.'|'.$note->start_date);

        $rows = [];
        foreach ($byPlayer as $playerId => $spells) {
            foreach ($spells as $spell) {
                $note = $notes->get($playerId.'|'.$spell['start']);
                $spell = self::withReturn($spell, $note?->returned_on);
                if ($spell['open'] || self::overlaps($spell, $from, $to)) {
                    $rows[] = ['player_id' => $playerId, ...$spell, 'note' => $note?->toDetail()];
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

    /** Player ids of the injury marks in held sessions (a subquery on attendances a, training_sessions s). */
    private function injuryMarks(): Builder
    {
        return DB::table('attendances as a')
            ->join('training_sessions as s', 's.id', '=', 'a.training_session_id')
            ->where('s.state', SessionState::Held->value)
            ->where(fn (Builder $q) => $q->where('a.status', AttendanceStatus::NotTraining->value)
                ->orWhere(fn (Builder $q) => $q->where('a.status', AttendanceStatus::AbsentExcused->value)
                    ->where('a.reason', AbsenceReason::Injury->value)))
            ->select('a.player_id');
    }

    /**
     * Players whose latest held mark (dated before $before, when given) is an
     * injury mark: an injury mark with no later held mark of theirs in spell
     * order (date, start time, session id). A correlated NOT EXISTS, no
     * window function, so it runs the same on sqlite, MySQL and pgsql.
     */
    private function latestMarkIsInjury(?string $before = null): Builder
    {
        return $this->injuryMarks()
            ->when($before !== null, fn (Builder $q) => $q->where('s.date', '<', $before))
            ->whereNotExists(fn (Builder $later) => $later->selectRaw('1')
                ->from('attendances as a2')
                ->join('training_sessions as s2', 's2.id', '=', 'a2.training_session_id')
                ->whereColumn('a2.player_id', 'a.player_id')
                ->where('s2.state', SessionState::Held->value)
                ->when($before !== null, fn (Builder $q) => $q->where('s2.date', '<', $before))
                ->where(fn (Builder $q) => $q->whereColumn('s2.date', '>', 's.date')
                    ->orWhere(fn (Builder $q) => $q->whereColumn('s2.date', 's.date')
                        ->where(fn (Builder $q) => $q->whereColumn('s2.start_time', '>', 's.start_time')
                            ->orWhere(fn (Builder $q) => $q->whereColumn('s2.start_time', 's.start_time')
                                ->whereColumn('s2.id', '>', 's.id'))))));
    }
}
