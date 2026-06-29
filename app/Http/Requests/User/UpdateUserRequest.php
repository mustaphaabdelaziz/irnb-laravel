<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'firstname' => ['nullable', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['string', 'max:20'],
            'gender' => ['nullable', 'string', 'in:Male,Female'],
            'privileges' => ['nullable', 'array'],
            'privileges.*' => ['string', 'in:user,admin'],
            'approved' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_user' => ['nullable', 'boolean'],
            'preferred_lng' => ['nullable', 'string', 'in:ar,fr,en'],
            'picture' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
