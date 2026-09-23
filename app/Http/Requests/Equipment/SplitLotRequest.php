<?php

namespace App\Http\Requests\Equipment;

use Illuminate\Foundation\Http\FormRequest;

class SplitLotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'condition' => ['required', 'in:New,Good,Fair,Poor,Damaged'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
