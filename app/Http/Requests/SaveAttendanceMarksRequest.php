<?php

namespace App\Http\Requests;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
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
            'theme' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.player_id' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'marks.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'marks.*.minutes' => ['nullable', 'integer', 'between:1,600', 'required_if:marks.*.status,late,left_early'],
            'marks.*.reason' => ['nullable', Rule::enum(AbsenceReason::class), 'required_if:marks.*.status,absent_excused'],
            'marks.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
