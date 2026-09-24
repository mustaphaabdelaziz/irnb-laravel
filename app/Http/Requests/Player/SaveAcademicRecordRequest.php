<?php

namespace App\Http\Requests\Player;

use App\Enums\AcademicCertificate;
use App\Enums\AcademicPeriod;
use App\Enums\EducationLevel;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\PlayerAcademicYear;
use App\Support\UiLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One trimester grade. Store names the school year and, when the player has no
 * such year yet, its school info (the year is created with the grade). Update
 * keeps the record in its year: only the trimester, grade and notes change.
 */
class SaveAcademicRecordRequest extends FormRequest
{
    private ?PlayerAcademicYear $year = null;

    private bool $yearLookedUp = false;

    public function authorize(): bool
    {
        return true; // gated by the route-name `permission` middleware
    }

    public function rules(): array
    {
        $record = $this->record();
        $year = $record?->academicYear ?? $this->existingYear();

        $rules = [
            'period' => [
                'required',
                Rule::in(AcademicPeriod::values()),
                Rule::unique('player_academic_records', 'period')
                    ->where('player_academic_year_id', $year?->id ?? 0)
                    ->ignore($record?->id),
            ],
            'gpa' => ['required', 'numeric', 'min:0', 'max:'.$this->scale($year)],
            'certificate' => ['nullable', Rule::in(AcademicCertificate::values())],
            'remark' => ['nullable', 'string', 'max:1000'],
        ];

        if ($record) {
            return $rules; // the year of a recorded grade cannot change
        }

        // School info describes a new year; for an existing one it is ignored.
        $school = $year ? ['exclude'] : ['nullable', 'string', 'max:255'];

        return [
            'academic_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            ...$rules,
            'education_level' => $year ? ['exclude'] : ['required', Rule::in(EducationLevel::values())],
            'institution' => $school,
            'field_of_study' => $school,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->route('player')->is_student) {
                $validator->errors()->add('student', UiLang::get('academic_not_student'));
            }
        });
    }

    private function record(): ?PlayerAcademicRecord
    {
        return $this->route('academicRecord');
    }

    /** The player's school year named by `academic_year`, if they already have it. */
    private function existingYear(): ?PlayerAcademicYear
    {
        if (! $this->yearLookedUp) {
            $this->yearLookedUp = true;
            $academicYear = $this->input('academic_year');

            /** @var Player $player */
            $player = $this->route('player');
            $this->year = is_numeric($academicYear)
                ? $player->academicYears()->where('academic_year', (int) $academicYear)->first()
                : null;
        }

        return $this->year;
    }

    /** Grades are out of the year's scale; a new year takes the scale of the submitted level. */
    private function scale(?PlayerAcademicYear $year): int
    {
        if ($year) {
            return $year->scale();
        }

        return EducationLevel::tryFrom((string) $this->input('education_level'))?->scale()
            ?? EducationLevel::Secondary->scale(); // unknown level: its own error is reported
    }
}
