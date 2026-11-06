<?php

namespace App\Http\Requests\Player;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Position;
use App\Support\ValidationAttributes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesRegistrations
{
    /** @return array<string, mixed> */
    protected function registrationRules(): array
    {
        return [
            // Required once the club has branches; with none set up there is nothing to pick.
            'registrations' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'array'],
            'registrations.*.branch_id' => ['required', 'integer', 'distinct', 'exists:branches,id'],
            'registrations.*.join_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'registrations.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'registrations.*.position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'registrations.*.other_position_ids' => ['nullable', 'array'],
            'registrations.*.other_position_ids.*' => ['integer', 'exists:positions,id'],
            'registrations.*.licence_number' => ['nullable', 'string', 'max:50'],
            'registrations.*.status_id' => ['nullable', 'integer', 'exists:player_statuses,id'],
            'registrations.*.left_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'registrations.*.sporting_status_id' => ['nullable', 'integer', 'exists:sporting_statuses,id'],
        ];
    }

    protected function validateRegistrations(Validator $validator, ?Player $player): void
    {
        $validator->after(function (Validator $validator) use ($player) {
            $existing = $player ? $player->registrations()->get()->keyBy('branch_id') : collect();
            $branchOf = Position::query()->pluck('branch_id', 'id');
            $categoryOwner = Category::query()->pluck('branch_id', 'id');
            $birthdate = $this->input('birthdate') ?: $player?->birthdate?->toDateString();

            foreach ((array) $this->input('registrations', []) as $i => $card) {
                $branchId = (int) ($card['branch_id'] ?? 0);
                $saved = $existing[$branchId] ?? null;
                $date = $card['join_date'] ?? null;

                // A legacy registration can be edited before its date is known; a new one cannot.
                if ($saved === null && empty($date)) {
                    $validator->errors()->add("registrations.$i.join_date", __('validation.required', ['attribute' => ValidationAttributes::for(app()->getLocale())['join_date']]));
                }
                if (! empty($date) && $birthdate && $date < $birthdate) {
                    $validator->errors()->add("registrations.$i.join_date", __('validation.join_date_before_birth'));
                }

                $category = $card['category_id'] ?? null;
                $keepsCategory = $saved !== null && (int) $saved->category_id === (int) $category;
                $categoryBranch = $categoryOwner[$category] ?? null;
                if ($category && ! $keepsCategory && $categoryBranch !== null && (int) $categoryBranch !== $branchId) {
                    $validator->errors()->add("registrations.$i.category_id", __('validation.category_other_branch'));
                }

                $main = $card['position_id'] ?? null;
                $keepsSaved = $saved !== null && (int) $saved->position_id === (int) $main;
                $owner = $branchOf[$main] ?? null;
                if ($main && ! $keepsSaved && $owner !== null && (int) $owner !== $branchId) {
                    $validator->errors()->add("registrations.$i.position_id", __('validation.position_other_branch'));
                }

                $others = array_map('intval', (array) ($card['other_position_ids'] ?? []));
                if ($main && in_array((int) $main, $others, true)) {
                    $validator->errors()->add("registrations.$i.other_position_ids", __('The main position is already listed.'));
                }
                foreach ($others as $other) {
                    if (($branchOf[$other] ?? null) !== null && (int) $branchOf[$other] !== $branchId) {
                        $validator->errors()->add("registrations.$i.other_position_ids", __('validation.position_other_branch'));
                        break;
                    }
                }
            }
        });
    }
}
