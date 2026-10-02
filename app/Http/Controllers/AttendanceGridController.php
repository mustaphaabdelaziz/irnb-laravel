<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceCode;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\MonthSheet;
use App\Services\Attendance\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month grid: players × sessions (see MonthSheet), filled with the
 * paper-sheet codes. A cell exists only where the player is on that
 * session's roster (frozen marks, or the expected roster before the first
 * save). A joint pre-season session shows in the grid of each of its
 * categories with its whole roster, since saving a column replaces the
 * session's full set of marks.
 */
class AttendanceGridController extends Controller
{
    public function show(Request $request, MonthSheet $sheet): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month);

        return Inertia::render('Attendance/Grid', [
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'kind' => $s->kind->value, 'state' => $s->state->value,
            ])->values(),
            'rows' => $players->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")])->values(),
            'cells' => (object) $cells,
            'attendanceCodes' => app(AttendanceStatusCatalog::class)->codes(),
        ]);
    }

    public function save(Request $request, MarkRecorder $recorder, Roster $roster): RedirectResponse
    {
        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.session_id' => ['required', 'integer', 'distinct', 'exists:training_sessions,id'],
            'columns.*.codes' => ['required', 'array', 'min:1'],
            'columns.*.codes.*' => ['nullable', 'string', 'max:12'],
        ]);

        $sessionIds = array_column($data['columns'], 'session_id');
        $sessions = TrainingSession::with(['attendances', 'categories'])->whereIn('id', $sessionIds)->get()->keyBy('id');

        $codes = AttendanceCode::fromSettings();
        $plan = [];
        $errors = [];
        foreach ($data['columns'] as $i => $column) {
            $session = $sessions->get($column['session_id']);

            if ($session->state === SessionState::Cancelled) {
                $errors["columns.$i"] = 'att.error.cancelled';

                continue;
            }

            $existing = $session->attendances->keyBy('player_id');
            $allowedIds = $existing->isNotEmpty()
                ? $existing->keys()->all()
                : $roster->expected($session->categoryIds(), $session->date, $roster->statusIdsFor($session))->modelKeys();
            $allowed = array_flip($allowedIds);
            $marks = [];

            foreach ($column['codes'] as $playerId => $code) {
                if (! ctype_digit((string) $playerId) || ! isset($allowed[(int) $playerId])) {
                    $errors["columns.$i.codes.$playerId"] = 'att.error.not_in_roster';

                    continue;
                }

                $parsed = $codes->parse($code);
                if ($parsed === null) {
                    $errors["columns.$i.codes.$playerId"] = 'att.error.invalid_code';

                    continue;
                }
                $previous = $existing->get((int) $playerId);
                $keepDetails = $previous?->status === $parsed['status'];
                $reason = $keepDetails ? $previous->reason?->value : null;
                if ($parsed['status'] === AttendanceStatus::AbsentExcused->value && $reason === null) {
                    $reason = AbsenceReason::Other->value;
                }

                $marks[] = [
                    'player_id' => (int) $playerId,
                    'status' => $parsed['status'],
                    'minutes' => $parsed['minutes'],
                    'reason' => $reason,
                    'note' => $keepDetails ? $previous->note : null,
                ];
            }
            $plan[] = [$session, $marks];
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
