<?php

namespace App\Http\Requests\Equipment;

use Illuminate\Foundation\Http\FormRequest;

class RentEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'equipment_item_id' => ['required', 'integer', 'exists:equipment_items,id'],
            'rentable_type' => ['required', 'string', 'in:Player,User'],
            'rentable_id' => ['required', 'integer'],
            'type' => ['nullable', 'in:rental,assignment'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'checkout_date' => ['nullable', 'date'],
            // Was after:today, which made backdating a rental impossible. The
            // due date only has to make sense relative to the checkout.
            'due_date' => ['nullable', 'date', 'after_or_equal:checkout_date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
