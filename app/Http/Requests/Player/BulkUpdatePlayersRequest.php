<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdatePlayersRequest extends FormRequest
{
    /**
     * Fields that may be set in bulk.
     *
     * An allow-list, never a blocklist: without it a crafted request could
     * rewrite any column — membership_id, archived, outstanding_debt — on
     * every selected player at once.
     */
    public const FIELDS = ['category_id', 'position_id', 'status_id', 'branches'];

    public function rules(): array
    {
        return [
            // Capped so one request cannot rewrite the entire table.
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:players,id'],

            'field' => ['required', Rule::in(self::FIELDS)],

            // Only meaningful for branches, which is many-to-many.
            'mode' => ['nullable', Rule::in(['replace', 'attach', 'detach'])],

            'value' => ['present'],
        ];
    }

    /**
     * The value is validated against whichever field was chosen, so a valid
     * field name cannot be paired with a foreign id from another table.
     */
    public function withValidator($validator): void
    {
        $validator->sometimes('value', ['nullable', 'integer', 'exists:categories,id'],
            fn ($input) => $input->field === 'category_id');

        $validator->sometimes('value', ['nullable', 'integer', 'exists:positions,id'],
            fn ($input) => $input->field === 'position_id');

        $validator->sometimes('value', ['nullable', 'integer', 'exists:player_statuses,id'],
            fn ($input) => $input->field === 'status_id');

        $validator->sometimes('value', ['array'],
            fn ($input) => $input->field === 'branches');

        $validator->sometimes('value.*', ['integer', 'exists:branches,id'],
            fn ($input) => $input->field === 'branches');
    }
}
