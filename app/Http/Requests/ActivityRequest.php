<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `permission` middleware already decided
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'activity_type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'location' => ['nullable', 'string', 'max:255'],
            'partners' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'responsible_board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'beneficiaries_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'external_volunteers_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'report' => ['nullable', 'string', 'max:100000'],
            'category_ids' => ['nullable', 'array', 'max:200'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'player_ids' => ['nullable', 'array', 'max:2000'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'board_member_ids' => ['nullable', 'array', 'max:200'],
            'board_member_ids.*' => ['integer', 'exists:board_members,id'],
        ];
    }

    /** The activity's own columns, counts defaulted to 0. @return array<string, mixed> */
    public function activityData(): array
    {
        $data = collect($this->validated())->except(['category_ids', 'player_ids', 'board_member_ids'])->all();
        $data['beneficiaries_count'] = (int) ($data['beneficiaries_count'] ?? 0);
        $data['external_volunteers_count'] = (int) ($data['external_volunteers_count'] ?? 0);

        return $data;
    }

    /** @return array{categories: int[], players: int[], boardMembers: int[]} */
    public function links(): array
    {
        return [
            'categories' => array_map('intval', $this->validated('category_ids') ?? []),
            'players' => array_map('intval', $this->validated('player_ids') ?? []),
            'boardMembers' => array_map('intval', $this->validated('board_member_ids') ?? []),
        ];
    }
}
