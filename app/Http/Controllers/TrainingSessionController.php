<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
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
     * An extra or pre-season session added by hand for one branch (regular ones
     * come from the schedule). Only a pre-season session can be shared by several
     * categories; `category_id` is its primary one.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Required once the club has branches; null = the whole club.
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
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
            $data['roster_status_ids'] = $this->sortedIds($data['roster_status_ids']);
        }
        $categoryIds = $data['kind'] === SessionKind::Preseason->value
            ? $this->uniqueIds([(int) $data['category_id'], ...($data['category_ids'] ?? [])])
            : [(int) $data['category_id']];
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $this->assertSlotFree($branchId, $categoryIds, $data['date'], $data['start_time']);

        try {
            $session = DB::transaction(function () use ($data, $categoryIds, $request) {
                $session = TrainingSession::create(Arr::except($data, 'category_ids') + ['state' => SessionState::Planned]);
                $session->categories()->syncWithoutDetaching($categoryIds);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CREATED, $session, ['kind' => $data['kind']]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (branch, category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return redirect()->route('attendance.sessions.show', $session)->with('success', 'flash.training_session_created');
    }

    /**
     * The session page's Edit dialog. Title, coach, notes, date and times can
     * always change (a new date or start keeps the first original date in
     * `moved_from`, so the generator never recreates the old slot). Branch,
     * roster statuses, categories and kind shape the roster, which is frozen
     * once marks are saved, so they change only before that; branch and kind
     * only on a session added by hand (a regular one follows its weekly
     * schedule). Fields left out of the request stay as they are.
     */
    public function update(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['date' => 'att.error.cancelled']);
        }
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'coach' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            // Start and end travel together (end is checked against start).
            'start_time' => ['required_with:end_time', 'date_format:H:i'],
            'end_time' => ['required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'branch_id' => ['sometimes', Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'roster_status_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'roster_status_ids.*' => ['integer', 'distinct', 'exists:player_statuses,id'],
            'kind' => ['sometimes', 'string'],
            'category_ids' => ['sometimes', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ], ['roster_status_ids.min' => 'att.error.roster_statuses_required']);

        $handAdded = $session->kind !== SessionKind::Regular;
        $kind = isset($data['kind']) ? SessionKind::tryFrom($data['kind']) : $session->kind;
        $branchId = array_key_exists('branch_id', $data) ? ($data['branch_id'] === null ? null : (int) $data['branch_id']) : $session->branch_id;
        $statusIds = array_key_exists('roster_status_ids', $data) ? $this->sortedIds($data['roster_status_ids']) : $this->sortedIds($session->roster_status_ids);
        $currentIds = $session->categoryIds();
        $chosenIds = isset($data['category_ids']) ? $this->uniqueIds($data['category_ids']) : null;
        $categoryIds = match (true) {
            $kind !== SessionKind::Preseason => [$session->category_id],
            $chosenIds !== null => $chosenIds,
            default => $currentIds,
        };
        $sortedCategoryIds = $categoryIds;
        sort($sortedCategoryIds);

        $errors = [];
        if ($kind === null || ($kind === SessionKind::Regular && $session->kind !== SessionKind::Regular)) {
            $errors['kind'] = 'att.error.not_hand_added';
        } elseif (! $handAdded && $kind !== $session->kind) {
            $errors['kind'] = 'att.error.not_hand_added';
        }
        if (! $handAdded && $branchId !== $session->branch_id) {
            $errors['branch_id'] = 'att.error.not_hand_added';
        }
        if ($chosenIds !== null && $kind !== SessionKind::Preseason && $chosenIds !== [$session->category_id]) {
            $errors['category_ids'] = 'att.error.not_preseason';
        }
        if ($session->attendances()->exists()) {
            $changed = [
                'branch_id' => $branchId !== $session->branch_id,
                'roster_status_ids' => $statusIds !== $this->sortedIds($session->roster_status_ids),
                'kind' => $kind !== $session->kind,
                'category_ids' => $sortedCategoryIds !== $currentIds,
            ];
            foreach (array_keys(array_filter($changed)) as $field) {
                $errors[$field] ??= 'att.error.already_marked';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $date = $data['date'] ?? $session->date;
        $start = $data['start_time'] ?? $session->start_time;
        $this->assertSlotFree($branchId, $categoryIds, $date, $start, $session->id);

        $attributes = Arr::only($data, ['title', 'coach', 'notes', 'date', 'start_time', 'end_time']) + [
            'branch_id' => $branchId,
            'kind' => $kind,
            // If the primary category is dropped, the first chosen one becomes primary.
            'category_id' => in_array($session->category_id, $categoryIds, true) ? $session->category_id : $categoryIds[0],
        ];
        // Written only when it changed: an older, unsorted stored set must not turn dirty on an unrelated edit.
        if ($statusIds !== $this->sortedIds($session->roster_status_ids)) {
            $attributes['roster_status_ids'] = $statusIds;
        }
        if ($date !== $session->date || $start !== $session->start_time) {
            $attributes['moved_from'] = $session->moved_from ?? $session->date;
        }

        try {
            DB::transaction(function () use ($session, $attributes, $categoryIds, $sortedCategoryIds, $currentIds, $request) {
                // Before the sync: a dropped category gets its slot back (pivot syncs fire no model events).
                $session->forgetGenerationMarks();
                $session->fill($attributes);
                $fields = array_keys($session->getDirty());
                if ($sortedCategoryIds !== $currentIds) {
                    $fields[] = 'category_ids';
                }
                $session->save();
                $session->categories()->sync($categoryIds);
                if ($fields !== []) {
                    ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_UPDATED, $session, ['fields' => array_values(array_unique($fields))]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (branch, category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return back()->with('success', 'flash.training_session_updated');
    }

    /** @return list<int>|null sorted ids; null or empty = none (the roster then takes the settings' default) */
    private function sortedIds(?array $ids): ?array
    {
        if ($ids === null || $ids === []) {
            return null;
        }
        $ids = $this->uniqueIds($ids);
        sort($ids);

        return $ids;
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

    /**
     * Deletes the session and every mark in it, so it leaves every report.
     * A regular session's slot (and its original slot if it was moved) is
     * remembered, so the weekly schedule never recreates it.
     */
    public function destroy(Request $request, TrainingSession $session): RedirectResponse
    {
        $back = ['category_id' => $session->category_id, 'month' => substr($session->date, 0, 7), 'branch_id' => $session->branch_id];

        DB::transaction(function () use ($session, $request) {
            if ($session->kind === SessionKind::Regular) {
                $slots = [[$session->date, $session->start_time]];
                if ($session->moved_from !== null) {
                    $slots[] = [$session->moved_from, $session->schedule?->start_time ?? $session->start_time];
                }
                DB::table('deleted_session_slots')->insertOrIgnore(array_map(fn (array $slot) => [
                    'branch_id' => $session->branch_id, 'category_id' => $session->category_id, 'date' => $slot[0], 'start_time' => $slot[1], 'created_at' => now(),
                ], $slots));
            }

            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_DELETED, $session, ['marks' => $session->attendances()->count()]);
            $session->attendances()->delete();
            $session->delete(); // its deleting hook frees the slot's generation marks (reads the pivot first)
            DB::table('training_session_category')->where('training_session_id', $session->id)->delete();
        });

        return redirect()->route('attendance.index', $back)->with('success', 'flash.training_session_deleted');
    }

    /** Erases every mark and makes the session planned again (a cancelled one too). */
    public function reset(Request $request, TrainingSession $session): RedirectResponse
    {
        DB::transaction(function () use ($session, $request) {
            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_RESET, $session, ['marks' => $session->attendances()->count()]);
            $session->attendances()->delete();
            $session->update(['state' => SessionState::Planned, 'cancel_reason' => null]);
        });

        return back()->with('success', 'flash.training_session_reset');
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
     * No other session of the same branch that any of these categories takes
     * part in may start at the same date and time (other branches may). The
     * pivot holds every category, primary or not, so this also covers the
     * unique (branch, category, date, start) key.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertSlotFree(?int $branchId, array $categoryIds, string $date, string $startTime, ?int $ignoreId = null, string $errorKey = 'start_time'): void
    {
        $taken = TrainingSession::where('branch_id', $branchId) // null → whereNull
            ->where('date', $date)->where('start_time', $startTime)
            ->whereIn('id', DB::table('training_session_category')->select('training_session_id')->whereIn('category_id', $categoryIds))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$errorKey => 'att.error.duplicate']);
        }
    }
}
