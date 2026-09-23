<?php

namespace App\Http\Requests\Player;

use App\Enums\AcademicPeriod;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Support\UiLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Store and update share one shape: a GPA out of 20 for one year + period. */
class SaveAcademicRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route-name `permission` middleware
    }

    public function rules(): array
    {
        /** @var Player $player */
        $player = $this->route('player');
        /** @var PlayerAcademicRecord|null $record */
        $record = $this->route('academicRecord');

        return [
            'academic_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'period' => [
                'required',
                Rule::in(AcademicPeriod::values()),
                Rule::unique('player_academic_records', 'period')
                    ->where('player_id', $player->id)
                    ->where('academic_year', (int) $this->input('academic_year'))
                    ->ignore($record?->id),
            ],
            'gpa' => ['required', 'numeric', 'min:0', 'max:20'],
            'remark' => ['nullable', 'string', 'max:1000'],
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
}
