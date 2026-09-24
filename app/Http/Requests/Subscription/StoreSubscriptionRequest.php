<?php

namespace App\Http\Requests\Subscription;

use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('kind')) {
            $this->merge(['kind' => Subscription::KIND_ANNUAL]);
        }
    }

    public function rules(): array
    {
        $exceptional = $this->input('kind') === Subscription::KIND_EXCEPTIONAL;
        $ignoreId = $this->route('subscription')?->id;

        return [
            'kind' => ['required', Rule::in(Subscription::KINDS)],
            'name' => [
                'required', 'string', 'max:255',
                // The (name, year) DB unique cannot see a NULL year, so one-off
                // charges are kept apart by name here.
                $exceptional
                    ? Rule::unique('subscriptions', 'name')->where('kind', Subscription::KIND_EXCEPTIONAL)->ignore($ignoreId)
                    : Rule::unique('subscriptions', 'name')->where('year', $this->input('year'))->ignore($ignoreId),
            ],
            // The season's END year: 2026 is the 2025/2026 season.
            'year' => $exceptional
                ? ['nullable']
                : ['required', 'integer', 'min:2000', 'max:'.(date('Y') + 6)],
            'amount_student' => ['required', 'numeric', 'min:0'],
            'amount_worker' => ['required', 'numeric', 'min:0'],
            'details' => ['nullable', 'string'],
            'is_mandatory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            // Per-category price overrides keyed by category id; a blank price
            // falls back to the default one above.
            'category_prices' => ['nullable', 'array'],
            'category_prices.*.amount_student' => ['nullable', 'numeric', 'min:0'],
            'category_prices.*.amount_worker' => ['nullable', 'numeric', 'min:0'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }

    /** The subscription's own columns: a one-off charge has no year and is never mandatory. */
    public function subscriptionAttributes(): array
    {
        $data = collect($this->validated())
            ->except(['category_ids', 'category_prices', 'branch_ids'])
            ->all();

        if ($data['kind'] === Subscription::KIND_EXCEPTIONAL) {
            $data['year'] = null;
            $data['is_mandatory'] = false;
        }

        return $data;
    }

    /**
     * Category ids mapped to their pivot prices, ready for sync().
     *
     * @return array<int, array{amount_student: float|null, amount_worker: float|null}>
     */
    public function categorySync(): array
    {
        $prices = $this->validated('category_prices') ?? [];
        $price = fn ($value) => ($value === null || $value === '') ? null : (float) $value;

        return collect($this->validated('category_ids') ?? [])
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'amount_student' => $price($prices[$id]['amount_student'] ?? null),
                'amount_worker' => $price($prices[$id]['amount_worker'] ?? null),
            ]])
            ->all();
    }
}
