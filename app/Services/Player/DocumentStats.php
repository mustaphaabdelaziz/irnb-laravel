<?php

namespace App\Services\Player;

use App\Models\DocumentType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of the paperwork is in, over any set of players: documents
 * required, received (expiring ones included), expiring within 30 days and
 * missing, plus how many players have a complete file.
 *
 * The caller passes an already-filtered players builder; its select list and
 * ordering are replaced and its filters kept. An inner derived table gives one
 * 1/0 column per active required type and measure (r_ = required,
 * e_ = expiring, m_ = missing — DocumentChecklist's SQL twins), and the outer
 * query sums them. No per-player PHP loop.
 */
final class DocumentStats
{
    /** @return array<string, mixed>|null null when no active required type exists */
    public function summary(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null): ?array
    {
        $types = $this->types();

        if ($types->isEmpty()) {
            return null;
        }

        $row = DB::query()
            ->fromSub($this->perPlayer($players, $types, $this->today($today), withCategory: false), 'per_player')
            ->selectRaw($this->aggregateSql($types))
            ->first();

        return $this->summaryFrom([$row], $types);
    }

    /**
     * The summary and one row per category, from ONE grouped query: the
     * categories partition the players, so the summary is their sum.
     *
     * @return array{summary: array<string, mixed>, by_category: list<array<string, mixed>>}|null
     */
    public function breakdown(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null): ?array
    {
        $types = $this->types();

        if ($types->isEmpty()) {
            return null;
        }

        // Current-locale name with the base name as fallback, like the
        // players list's category chart.
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $localeColumn = 'categories.name_'.$locale;

        $rows = DB::query()
            ->fromSub($this->perPlayer($players, $types, $this->today($today), withCategory: true), 'per_player')
            ->leftJoin('categories', 'categories.id', '=', 'per_player.category_id')
            ->groupBy('per_player.category_id', 'categories.name', $localeColumn)
            ->selectRaw(
                "per_player.category_id, COALESCE(NULLIF({$localeColumn}, ''), categories.name) as category_name, "
                .$this->aggregateSql($types)
            )
            ->get();

        $byCategory = $rows->map(fn (object $row): array => [
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'name' => $row->category_name ?: null,
            ...$this->rowCounts($row, $types),
            'players_total' => (int) $row->players_total,
            'players_complete' => (int) $row->players_complete,
        ])->all();

        return [
            'summary' => $this->summaryFrom($rows->all(), $types),
            'by_category' => self::worstFirst($byCategory),
        ];
    }

    /** @return Collection<int, DocumentType> */
    private function types(): Collection
    {
        return DocumentType::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->ordered()
            ->get();
    }

    private function today(?CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();
    }

    /** One row per player: r_{id}, e_{id}, m_{id} for every type (and category_id when grouping). */
    private function perPlayer(EloquentBuilder|QueryBuilder $players, Collection $types, CarbonImmutable $today, bool $withCategory): QueryBuilder
    {
        $query = $players instanceof EloquentBuilder ? (clone $players)->toBase() : clone $players;
        $query->reorder()->select($withCategory ? ['players.category_id'] : []);

        foreach ($types as $type) {
            $terms = [
                'r' => DocumentChecklist::requiredTypeSql($type, $today),
                'e' => DocumentChecklist::expiringTypeSql($type, $today),
                'm' => DocumentChecklist::missingTypeSql($type, $today),
            ];

            foreach ($terms as $prefix => [$sql, $bindings]) {
                $query->selectRaw("{$sql} as {$prefix}_{$type->id}", $bindings);
            }
        }

        return $query;
    }

    /** Outer select: player counts and one SUM per inner column. Type ids are integers from the database. */
    private function aggregateSql(Collection $types): string
    {
        $missingTotal = $types->map(fn (DocumentType $type) => "m_{$type->id}")->implode(' + ');

        $columns = [
            'COUNT(*) as players_total',
            "SUM(CASE WHEN ({$missingTotal}) = 0 THEN 1 ELSE 0 END) as players_complete",
        ];

        foreach ($types as $type) {
            foreach (['r', 'e', 'm'] as $prefix) {
                $columns[] = "SUM({$prefix}_{$type->id}) as {$prefix}_{$type->id}";
            }
        }

        return implode(', ', $columns);
    }

    /**
     * @param  iterable<object>  $rows  aggregate rows (one, or one per category)
     * @return array<string, mixed>
     */
    private function summaryFrom(iterable $rows, Collection $types): array
    {
        $perType = $types->mapWithKeys(fn (DocumentType $type) => [$type->id => ['required' => 0, 'expiring' => 0, 'missing' => 0]])->all();
        $playersTotal = 0;
        $playersComplete = 0;

        foreach ($rows as $row) {
            $playersTotal += (int) $row->players_total;
            $playersComplete += (int) $row->players_complete;

            foreach ($types as $type) {
                $perType[$type->id]['required'] += (int) $row->{"r_{$type->id}"};
                $perType[$type->id]['expiring'] += (int) $row->{"e_{$type->id}"};
                $perType[$type->id]['missing'] += (int) $row->{"m_{$type->id}"};
            }
        }

        $byType = $types->map(fn (DocumentType $type): array => [
            'id' => $type->id,
            'name' => $type->localized_name,
            ...self::counts(...$perType[$type->id]),
        ])->all();

        return [
            ...self::counts(
                required: array_sum(array_column($perType, 'required')),
                expiring: array_sum(array_column($perType, 'expiring')),
                missing: array_sum(array_column($perType, 'missing')),
            ),
            'players_complete' => $playersComplete,
            'players_total' => $playersTotal,
            'by_type' => self::worstFirst($byType),
        ];
    }

    /** Totals of one aggregate row over every type. */
    private function rowCounts(object $row, Collection $types): array
    {
        $required = $expiring = $missing = 0;

        foreach ($types as $type) {
            $required += (int) $row->{"r_{$type->id}"};
            $expiring += (int) $row->{"e_{$type->id}"};
            $missing += (int) $row->{"m_{$type->id}"};
        }

        return self::counts(required: $required, expiring: $expiring, missing: $missing);
    }

    /** @return array{required: int, received: int, expiring: int, missing: int, pct: ?float} */
    private static function counts(int $required, int $expiring, int $missing): array
    {
        $received = $required - $missing;

        return [
            'required' => $required,
            'received' => $received,
            'expiring' => $expiring,
            'missing' => $missing,
            'pct' => $required > 0 ? round($received / $required * 100, 1) : null,
        ];
    }

    /**
     * Worst percentage first, rows with nothing required last, then by name.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function worstFirst(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$a['pct'] === null, $a['pct'] ?? 0, (string) $a['name']]
            <=> [$b['pct'] === null, $b['pct'] ?? 0, (string) $b['name']]);

        return $rows;
    }
}
