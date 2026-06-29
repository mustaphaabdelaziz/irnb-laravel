<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

class StorePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'father' => ['nullable', 'string', 'max:255'],
            'grandfather' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', 'string', 'in:Male,Female'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'status_class' => ['nullable', 'string', 'max:255'],
            'status_value' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_student' => ['nullable', 'boolean'],
            'member_job_id' => ['nullable', 'integer', 'exists:member_jobs,id'],
            'join_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'team' => ['nullable', 'string', 'max:255'],
            'skill_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'health_medical_conditions' => ['nullable', 'string'],
            'health_blood_group_rhesus' => ['nullable', 'string', 'max:10'],
            'picture' => ['nullable', 'image', 'max:5120'],
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:255'],
            'emergency_contacts.*.phones' => ['nullable', 'array'],
        ];
    }
}
