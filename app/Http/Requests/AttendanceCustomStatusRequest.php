<?php

namespace App\Http\Requests;

use App\Models\AttendanceCustomStatus;
use App\Services\Attendance\AttendanceCode;
use App\Services\Attendance\AttendanceStatusCatalog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A custom attendance code, added or edited on the settings page. The code
 * follows the built-in codes' rule (1 to 3 letters, any script) and must
 * differ, ignoring case, from every other code, built-in or custom. Access
 * is checked by the `permission` middleware (attendance/edit).
 */
class AttendanceCustomStatusRequest extends FormRequest
{
    private const LABELS = ['label_ar', 'label_fr', 'label_en'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $labels = [];
        foreach (self::LABELS as $field) {
            // At least one name, in any language; the others fall back to it.
            $labels[$field] = ['nullable', 'string', 'max:40', 'required_without_all:'.implode(',', array_diff(self::LABELS, [$field]))];
        }

        return [
            'code' => ['required', 'string', 'regex:/^\p{L}{1,3}$/u', $this->uniqueCode(...)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ...$labels,
            'behaviour' => ['required', Rule::in(AttendanceCustomStatus::BEHAVIOURS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'att.error.code_format',
            'code.regex' => 'att.error.code_format',
            'color.required' => 'att.error.color_format',
            'color.regex' => 'att.error.color_format',
            'label_ar.required_without_all' => 'att.error.label_required',
            'label_fr.required_without_all' => 'att.error.label_required',
            'label_en.required_without_all' => 'att.error.label_required',
        ];
    }

    /** Spaces trimmed out of the code before the rules run, so " v " is "v". */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => (string) preg_replace('/\s+/u', '', $this->input('code'))]);
        }
    }

    /** @return array{code: string, color: string, label_ar: ?string, label_fr: ?string, label_en: ?string, behaviour: string, is_active?: bool} normalised */
    public function attributesToSave(): array
    {
        $data = $this->validated();
        $data['code'] = AttendanceCode::normalise($data['code']);
        $data['color'] = strtolower($data['color']);
        foreach (self::LABELS as $field) {
            $data[$field] = ($data[$field] ?? null) ?: null;
        }

        return $data;
    }

    private function uniqueCode(string $attribute, mixed $value, Closure $fail): void
    {
        $code = AttendanceCode::normalise((string) $value);
        $self = $this->route('customStatus')?->key;

        foreach (app(AttendanceStatusCatalog::class)->codes() as $key => $row) {
            if ($key !== $self && AttendanceCode::normalise($row['code']) === $code) {
                $fail('att.error.code_taken');

                return;
            }
        }
    }
}
