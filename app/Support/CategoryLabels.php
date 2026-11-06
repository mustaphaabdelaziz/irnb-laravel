<?php

namespace App\Support;

use App\Models\Category;

/**
 * Category names where no single branch applies: filters, charts, team labels.
 * The sport is added only when the name repeats ("Senior · Swimming"). The server
 * twin of sportLabeller() in resources/js/lib/sportLabels.js. Build one per request.
 */
final class CategoryLabels
{
    /** @param  array<int, string>  $labels  in name order */
    private function __construct(private readonly array $labels) {}

    public static function make(): self
    {
        $categories = Category::query()
            ->with('branch:id,name,name_ar,name_fr,name_en')
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id']);
        $counts = $categories->countBy('name');

        return new self($categories->mapWithKeys(fn (Category $category) => [
            $category->id => $counts[$category->name] > 1 && $category->branch !== null
                ? $category->localized_name.' · '.$category->branch->localized_name
                : $category->localized_name,
        ])->all());
    }

    public function of(int|string|null $id): ?string
    {
        return $id === null ? null : ($this->labels[(int) $id] ?? null);
    }

    /** @return list<array{value: int, label: string}> */
    public function options(): array
    {
        return array_map(
            fn (int $id, string $label) => ['value' => $id, 'label' => $label],
            array_keys($this->labels),
            array_values($this->labels),
        );
    }
}
