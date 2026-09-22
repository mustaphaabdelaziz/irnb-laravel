<?php

namespace App\Http\Requests\Equipment;

use Closure;
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
            // A team player, or someone from outside the club (free text).
            // Work equipment is only ever assigned to a player; outsiders can only rent.
            'rentable_type' => ['required', 'string', 'in:Player,External', function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('type') === 'assignment' && $value !== 'Player') {
                    $fail(__('Equipment can only be assigned to a player.'));
                }
            }],
            // Required only for a player; an external person has no id.
            'rentable_id' => ['required_if:rentable_type,Player', 'nullable', 'integer'],
            'external_name' => ['required_if:rentable_type,External', 'nullable', 'string', 'max:255'],
            'external_phone' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'in:rental,assignment'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'checkout_date' => ['nullable', 'date'],
            // How many days the item is expected to be out. The due date is
            // derived from it, and drives the overdue flag.
            'expected_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
