<?php

namespace App\Http\Requests;

use App\Enums\AbsenceReason;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStatusCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The full set of marks of one session, plus its log. Access is checked by the `permission` middleware. */
class SaveAttendanceMarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coach' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.player_id' => ['required', 'integer', 'distinct', 'exists:players,id'],
            // Any status of the catalog; a hidden custom code only where the player's mark already has it (see after()).
            'marks.*.status' => ['required', 'string', Rule::in(app(AttendanceStatusCatalog::class)->keys())],
            'marks.*.minutes' => ['nullable', 'integer', 'between:1,600', 'required_if:marks.*.status,late,left_early'],
            'marks.*.reason' => ['nullable', Rule::enum(AbsenceReason::class), 'required_if:marks.*.status,absent_excused'],
            'marks.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A hidden custom code can no longer be picked: it is kept only on a
     * player whose saved mark in this session already has it, so re-saving
     * an old session never fails.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $catalog = app(AttendanceStatusCatalog::class);
            $marks = $this->input('marks');
            if (! is_array($marks)) {
                return;
            }
            $session = $this->route('session');
            $existing = null;
            foreach ($marks as $i => $mark) {
                $status = is_array($mark) ? ($mark['status'] ?? null) : null;
                if (! is_string($status) || ! $catalog->has($status) || $catalog->isActive($status)) {
                    continue;
                }
                $existing ??= $session instanceof TrainingSession
                    ? $session->attendances()->pluck('status', 'player_id')->all()
                    : [];
                if (($existing[(int) ($mark['player_id'] ?? 0)] ?? null) !== $status) {
                    $validator->errors()->add("marks.$i.status", 'att.error.code_hidden');
                }
            }
        }];
    }
}
