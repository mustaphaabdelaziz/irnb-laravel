<?php

namespace App\Http\Requests\Mission;

use App\Models\BoardMember;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A mission order and its travelers (create, or edit an active order — the
 * service refuses the others). A traveler is a typed name (no type) or a
 * user / board member / player that exists; name and function are the
 * sheet's own copy.
 */
class SaveMissionOrderRequest extends FormRequest
{
    /** Traveler type → the model its id must exist in. */
    private const MODELS = [
        MissionOrderTraveler::USER => User::class,
        MissionOrderTraveler::BOARD_MEMBER => BoardMember::class,
        MissionOrderTraveler::PLAYER => Player::class,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            'destination' => ['required', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'departure_date' => ['required', 'date'],
            'return_date' => ['nullable', 'date', 'after_or_equal:departure_date'],
            'transport' => ['nullable', 'string', 'max:100'],
            'travelers' => ['required', 'array', 'min:1', 'max:100'],
            'travelers.*.traveler_type' => ['nullable', Rule::in(MissionOrderTraveler::TYPES)],
            'travelers.*.traveler_id' => ['nullable', 'required_with:travelers.*.traveler_type', 'integer'],
            'travelers.*.name' => ['required', 'string', 'max:200'],
            'travelers.*.function' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** Every picked person still exists (one query per type). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $rows = collect($this->input('travelers', []));
            // Editing: a person already on this order who no longer exists stays (the service keeps them as a typed name).
            $order = $this->route('missionOrder');
            $already = $order instanceof MissionOrder
                ? $order->travelers()->whereNotNull('traveler_type')->get(['traveler_type', 'traveler_id'])
                    ->map(fn ($t) => $t->traveler_type.'|'.$t->traveler_id)->all()
                : [];
            foreach (self::MODELS as $type => $model) {
                $mine = $rows->filter(fn ($row) => is_array($row) && ($row['traveler_type'] ?? null) === $type);
                if ($mine->isEmpty()) {
                    continue;
                }
                $found = $model::query()->whereIn('id', $mine->pluck('traveler_id')->map(fn ($id) => (int) $id)->unique()->values())
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
                foreach ($mine as $index => $row) {
                    if (! in_array((int) $row['traveler_id'], $found, true) && ! in_array($type.'|'.(int) $row['traveler_id'], $already, true)) {
                        $validator->errors()->add("travelers.{$index}.traveler_id", __('validation.exists', ['attribute' => '#'.(is_int($index) ? $index + 1 : $index)]));
                    }
                }
            }
        });
    }
}
