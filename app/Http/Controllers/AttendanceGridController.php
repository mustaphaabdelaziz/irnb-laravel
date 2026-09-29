<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceCode;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month grid: players × sessions, filled with the paper-sheet codes. A cell
 * exists only where the player is on that session's roster (frozen marks,
 * or the expected roster before the first save).
 */
class AttendanceGridController extends Controller
{
    public function show(Request $request, SessionGenerator $generator, Roster $roster): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $generator->forMonth($category->id, $anchor->year, $anchor->month);

        $sessions = TrainingSession::where('category_id', $category->id)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->whereBetween('date', [$anchor->startOfMonth()->toDateString(), $anchor->endOfMonth()->toDateString()])
            ->with('attendances')
            ->orderBy('date')->orderBy('start_time')
            ->get();

        $cells = [];
        $expectedByDate = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = AttendanceCode::format($mark->status, $mark->minutes);
                }

                continue;
            }
            $expectedByDate[$session->date] ??= $roster->expected($category->id, $session->date)->modelKeys();
            foreach ($expectedByDate[$session->date] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $rows = Player::whereIn('id', array_keys($cells))->orderBy('lastname')->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname'])
            ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]);

        return Inertia::render('Attendance/Grid', [
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'kind' => $s->kind->value, 'state' => $s->state->value,
            ])->values(),
            'rows' => $rows,
            'cells' => (object) $cells,
        ]);
    }

    public function save(Request $request, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.session_id' => ['required', 'integer', 'distinct', 'exists:training_sessions,id'],
            'columns.*.codes' => ['required', 'array', 'min:1'],
            'columns.*.codes.*' => ['nullable', 'string', 'max:6'],
        ]);

        $plan = [];
        $errors = [];
        foreach ($data['columns'] as $i => $column) {
            $session = TrainingSession::with('attendances')->findOrFail($column['session_id']);
            $existing = $session->attendances->keyBy('player_id');
            $marks = [];

            foreach ($column['codes'] as $playerId => $code) {
                $parsed = AttendanceCode::parse($code);
                if ($parsed === null) {
                    $errors["columns.$i.codes.$playerId"] = 'att.error.invalid_code';

                    continue;
                }
                $previous = $existing->get((int) $playerId);
                $keepDetails = $previous?->status === $parsed['status'];
                $reason = $keepDetails ? $previous->reason?->value : null;
                if ($parsed['status'] === AttendanceStatus::AbsentExcused && $reason === null) {
                    $reason = AbsenceReason::Other->value;
                }

                $marks[] = [
                    'player_id' => (int) $playerId,
                    'status' => $parsed['status']->value,
                    'minutes' => $parsed['minutes'],
                    'reason' => $reason,
                    'note' => $keepDetails ? $previous->note : null,
                ];
            }
            $plan[] = [$session, $marks];
        }

        $unknown = array_diff(
            collect($plan)->flatMap(fn ($p) => array_column($p[1], 'player_id'))->unique()->all(),
            Player::whereIn('id', collect($plan)->flatMap(fn ($p) => array_column($p[1], 'player_id'))->all())->pluck('id')->all(),
        );
        if ($unknown !== []) {
            $errors['columns'] = 'att.error.invalid_code';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($plan, $recorder, $request) {
            foreach ($plan as [$session, $marks]) {
                $recorder->save($session, $marks, $request->user());
            }
        });

        return back()->with('success', 'flash.attendance_saved');
    }
}
