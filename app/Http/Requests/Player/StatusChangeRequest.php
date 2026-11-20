<?php

namespace App\Http\Requests\Player;

use App\Models\BranchRegistration;
use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A history line typed or corrected by hand on the player page. */
class StatusChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_status_id' => ['nullable', 'integer', 'exists:player_statuses,id'],
            'to_status_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'nullable', 'integer', 'exists:player_statuses,id'],
            'changed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $day = $this->input('changed_on');
            if (! is_string($day) || $validator->errors()->has('changed_on')) {
                return;
            }

            $player = $this->route('player');
            $birth = $player instanceof Player ? $player->birthdate?->toDateString() : null;
            if ($birth !== null && $day < $birth) {
                $validator->errors()->add('changed_on', __('validation.status_change_before_birth'));
            }

            $registration = $this->route('registration') ?? $this->route('statusChange')?->registration;
            $joined = $registration instanceof BranchRegistration ? $registration->join_date?->toDateString() : null;
            if ($joined !== null && $day < $joined) {
                $validator->errors()->add('changed_on', __('validation.status_change_before_join'));
            }
        });
    }
}
