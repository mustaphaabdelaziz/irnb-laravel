<?php

namespace App\Http\Requests\User;

use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => Username::normalize($this->username)]);
    }

    public function rules(): array
    {
        return [
            'username' => Username::rules(),
            'password' => ['required', 'confirmed', Password::defaults()],
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['string', 'max:20'],
            'gender' => ['nullable', 'string', 'in:Male,Female'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'preferred_lng' => ['nullable', 'string', 'in:ar,fr,en'],
        ];
    }
}
