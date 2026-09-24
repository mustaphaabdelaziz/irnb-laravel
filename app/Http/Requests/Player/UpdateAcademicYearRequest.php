<?php

namespace App\Http\Requests\Player;

use App\Enums\EducationLevel;
use App\Models\PlayerAcademicYear;
use App\Support\UiLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** School info of one year. The year itself is fixed; delete it to move grades elsewhere. */
class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route-name `permission` middleware
    }

    public function rules(): array
    {
        return [
            'education_level' => ['required', Rule::in(EducationLevel::values())],
            'institution' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->route('player')->is_student) {
                $validator->errors()->add('student', UiLang::get('academic_not_student'));
            }

            // A new level may shrink the scale (/20 → /10 for primary): refuse
            // it while a grade of the year would no longer fit.
            $level = EducationLevel::tryFrom((string) $this->input('education_level'));
            /** @var PlayerAcademicYear $year */
            $year = $this->route('academicYear');

            if ($level && $year->records()->where('gpa', '>', $level->scale())->exists()) {
                $validator->errors()->add('education_level', UiLang::get('academic_level_scale_conflict'));
            }
        });
    }
}
