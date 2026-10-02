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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrainingSessionController extends Controller
{
    /**
     * An extra or pre-season session added by hand (regular ones come from the
     * schedule). Only a pre-season session can be shared by several
     * categories; `category_id` is its primary one.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'category_ids' => ['exclude_unless:kind,preseason', 'nullable', 'array'],
            'category_ids.*' => ['exclude_unless:kind,preseason', 'integer', 'distinct', 'exists:categories,id'],
            'kind' => ['required', Rule::in([SessionKind::Extra->value, SessionKind::Preseason->value])],
            'title' => ['nullable', 'string', 'max:150'],
            // The player statuses its roster is drawn from (the dialog pre-checks the settings' default).
            'roster_status_ids' => ['nullable', 'array', 'min:1'],
            'roster_status_ids.*' => ['integer', 'distinct', 'exists:player_statuses,id'],
            ...$this->slotRules(),
        ], ['roster_status_ids.min' => 'att.error.roster_statuses_required']);
        if (isset($data['roster_status_ids'])) {
            $data['roster_status_ids'] = array_values(array_map('intval', $data['roster_status_ids']));
        }
        $categoryIds = $data['kind'] === SessionKind::Preseason->value
            ? $this->uniqueIds([(int) $data['category_id'], ...($data['category_ids'] ?? [])])
            : [(int) $data['category_id']];
        $this->assertSlotFree($categoryIds, $data['date'], $data['start_time']);

        try {
            $session = DB::transaction(function () use ($data, $categoryIds, $request) {
                $session = TrainingSession::create(Arr::except($data, 'category_ids') + ['state' => SessionState::Planned]);
                $session->categories()->syncWithoutDetaching($categoryIds);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CREATED, $session, ['kind' => $data['kind']]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return redirect()->route('attendance.sessions.show', $session)->with('success', 'flash.training_session_created');
    }

    /**
     * The categories of a pre-season session, editable until its marks are
     * first saved (the roster is frozen from then on). If the primary
     * category is dropped, the first chosen one becomes primary.
     */
    public function updateCategories(Request $request, TrainingSession $session): RedirectResponse
    {
        $data = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ]);

        $error = match (true) {
            $session->kind !== SessionKind::Preseason => 'att.error.not_preseason',
            $session->state === SessionState::Cancelled => 'att.error.cancelled',
            $session->state === SessionState::Held, $session->attendances()->exists() => 'att.error.already_marked',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['category_ids' => $error]);
        }

        $ids = $this->uniqueIds($data['category_ids']);
        $this->assertSlotFree($ids, $session->date, $session->start_time, $session->id, 'category_ids');
        $primary = in_array($session->category_id, $ids, true) ? $session->category_id : $ids[0];

        try {
            DB::transaction(function () use ($session, $ids, $primary, $request) {
                $session->update(['category_id' => $primary]);
                $session->categories()->sync($ids);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CATEGORIES_CHANGED, $session, ['count' => count($ids)]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['category_ids' => 'att.error.duplicate']);
        }

        return back()->with('success', 'flash.training_session_categories_saved');
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
        $this->assertSlotFree($session->categoryIds(), $data['date'], $data['start_time'], $session->id);

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

    /** @return array<int, int> */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * No other session that any of these categories takes part in may start
     * at the same date and time. The pivot holds every category, primary or
     * not, so this also covers the P1 unique (category, date, start) key.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertSlotFree(array $categoryIds, string $date, string $startTime, ?int $ignoreId = null, string $errorKey = 'start_time'): void
    {
        $taken = TrainingSession::where('date', $date)->where('start_time', $startTime)
            ->whereIn('id', DB::table('training_session_category')->select('training_session_id')->whereIn('category_id', $categoryIds))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$errorKey => 'att.error.duplicate']);
        }
    }
}
