<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Validation\ValidationException;

/**
 * The one rule: a category must belong to its branch. A value already saved stays
 * until edited, as positions do, and a category with no owner fits anywhere.
 */
final class CategoryOwnership
{
    /** Whether any new value belongs to a branch not listed. No branches listed means no check. */
    public static function outside(iterable|int|null $ids, iterable $branchIds, iterable|int|null $stored = null): bool
    {
        $branches = collect($branchIds)->map(fn ($id) => (int) $id)->all();
        $new = collect(is_iterable($ids) ? $ids : [$ids])->filter()->map(fn ($id) => (int) $id)
            ->diff(collect(is_iterable($stored) ? $stored : [$stored])->filter()->map(fn ($id) => (int) $id))->values()->all();

        if ($branches === [] || $new === []) {
            return false;
        }

        return Category::whereIn('id', $new)->whereNotNull('branch_id')->whereNotIn('branch_id', $branches)->exists();
    }

    /**
     * A null branch takes any category.
     *
     * @throws ValidationException
     */
    public static function assertIn(string $field, iterable|int|null $ids, ?int $branchId, iterable|int|null $stored = null): void
    {
        if ($branchId !== null && self::outside($ids, [$branchId], $stored)) {
            throw ValidationException::withMessages([$field => __('validation.category_other_branch')]);
        }
    }
}
