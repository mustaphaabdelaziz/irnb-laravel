<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Support\CategoryLabels;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures counted per registration, in SQL: a two-branch player counts once in
 * each. Players with no registration count once, on their own columns.
 */
final class RegistrationCounts
{
    /**
     * One row per registration of these players. Left registrations are out
     * unless asked for.
     *
     * @param  list<int|string>|null  $branches  null: every branch
     */
    public static function rows(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false, ?array $categories = null): QueryBuilder
    {
        $ids = clone $players;
        $ids = $ids instanceof EloquentBuilder ? $ids->toBase() : $ids;

        $query = DB::query()->fromSub(BranchRegistration::everyRow(), 'reg')
            ->whereIn('reg.player_id', $ids->reorder()->select('players.id'))
            ->when($branches !== null, fn ($q) => $q->whereIn('reg.branch_id', $branches))
            ->when($categories !== null, fn ($q) => $q->whereIn('reg.category_id', $categories));

        return $includeLeft ? $query : BranchRegistration::constrainActive($query, 'reg.status_id');
    }

    /** @return Collection<int, object{key: int|null, name: string|null, total: int}> */
    public static function byCategory(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false, ?array $categories = null, ?CategoryLabels $labels = null): Collection
    {
        $labels ??= CategoryLabels::make();

        return self::grouped(self::rows($players, $branches, $includeLeft, $categories), 'categories', 'category_id')
            ->map(function (object $row) use ($labels) {
                $row->name = $labels->of($row->key) ?? $row->name;

                return $row;
            });
    }

    /** @return Collection<int, object{key: int|null, name: string|null, total: int}> */
    public static function byStatus(EloquentBuilder|QueryBuilder $players, ?array $branches = null, bool $includeLeft = false, ?array $categories = null): Collection
    {
        return self::grouped(self::rows($players, $branches, $includeLeft, $categories), 'player_statuses', 'status_id');
    }

    /** The locale's name, falling back to the base name, chosen in SQL like the dashboard's lookups. */
    private static function grouped(QueryBuilder $rows, string $table, string $foreignKey): Collection
    {
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $column = "{$table}.name_{$locale}";

        return $rows->leftJoin($table, "{$table}.id", '=', "reg.{$foreignKey}")
            ->groupBy("reg.{$foreignKey}", "{$table}.name", $column)
            ->selectRaw("reg.{$foreignKey} as lookup_key, COALESCE(NULLIF({$column}, ''), {$table}.name) as name, COUNT(*) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => (object) ['key' => $row->lookup_key === null ? null : (int) $row->lookup_key, 'name' => $row->name, 'total' => (int) $row->total]);
    }
}
