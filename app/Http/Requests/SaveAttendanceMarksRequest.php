<?php

namespace App\Http\Requests;

use App\Enums\AbsenceReason;
use App\Services\Attendance\AttendanceStatusCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Any status of the catalog, hidden custom codes included: re-saving an old session must not fail.
            'marks.*.status' => ['required', 'string', Rule::in(app(AttendanceStatusCatalog::class)->keys())],
            'marks.*.minutes' => ['nullable', 'integer', 'between:1,600', 'required_if:marks.*.status,late,left_early'],
            'marks.*.reason' => ['nullable', Rule::enum(AbsenceReason::class), 'required_if:marks.*.status,absent_excused'],
            'marks.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
