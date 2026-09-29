<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrainingSessionController extends Controller
{
    /** An extra or pre-season session added by hand (regular ones come from the schedule). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'kind' => ['required', Rule::in([SessionKind::Extra->value, SessionKind::Preseason->value])],
            ...$this->slotRules(),
        ]);
        $this->assertSlotFree((int) $data['category_id'], $data['date'], $data['start_time']);

        try {
            $session = DB::transaction(function () use ($data, $request) {
                $session = TrainingSession::create($data + ['state' => SessionState::Planned]);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CREATED, $session, ['kind' => $data['kind']]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return redirect()->route('attendance.sessions.show', $session)->with('success', 'flash.training_session_created');
    }

    public function cancel(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['reason' => 'att.error.cancelled']);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($session, $data, $request) {
            $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => $data['reason']]);
            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CANCELLED, $session);
        });

        return back()->with('success', 'flash.training_session_cancelled');
    }

    /** `moved_from` keeps the first original date so the generator never recreates that slot. */
    public function move(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['date' => 'att.error.cancelled']);
        }
        $data = $request->validate($this->slotRules());
        $this->assertSlotFree($session->category_id, $data['date'], $data['start_time'], $session->id);

        try {
            DB::transaction(function () use ($session, $data, $request) {
                $session->update($data + ['moved_from' => $session->moved_from ?? $session->date]);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_MOVED, $session);
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return back()->with('success', 'flash.training_session_moved');
    }

    private function slotRules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }

    private function assertSlotFree(int $categoryId, string $date, string $startTime, ?int $ignoreId = null): void
    {
        $taken = TrainingSession::where('category_id', $categoryId)->where('date', $date)->where('start_time', $startTime)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }
    }
}
