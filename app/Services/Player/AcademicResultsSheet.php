<?php

namespace App\Services\Player;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerAcademicYear;
use Illuminate\Support\Collection;

/**
 * One school year's results for the club's active students, grouped by
 * category: the sheet the office prints to see how every team is doing at
 * school. Each category lists its students best year average first (compared
 * on /20 so primary and later levels rank together); students with no grade
 * that year come last, so the gaps are visible.
 */
final class AcademicResultsSheet
{
    /**
     * @return Collection<int, array{category: ?Category, rows: Collection<int, array{player: Player, year: ?PlayerAcademicYear, on20: ?float}>}>
     */
    public static function build(int $schoolYear, ?int $categoryId = null): Collection
    {
        $players = Player::query()
            ->where('is_student', true)
            ->where('archived', false)
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->with([
                'category',
                'academicYears' => fn ($query) => $query->where('academic_year', $schoolYear)->with('records'),
            ])
            ->get();

        return $players
            ->groupBy(fn (Player $player) => $player->category_id ?? 0)
            ->map(fn (Collection $group) => [
                'category' => $group->first()->category,
                'rows' => self::rank($group),
            ])
            // Categories by name, the order the players list uses; no category last.
            ->sortBy(fn (array $section) => [$section['category'] === null ? 1 : 0, mb_strtolower($section['category']?->name ?? '')])
            ->values();
    }

    /** @return Collection<int, array{player: Player, year: ?PlayerAcademicYear, on20: ?float}> */
    private static function rank(Collection $players): Collection
    {
        return $players
            ->map(function (Player $player) {
                $year = $player->academicYears->first();
                $average = $year?->average();

                return [
                    'player' => $player,
                    'year' => $year,
                    'on20' => $average === null ? null : round($average * 20 / $year->scale(), 2),
                ];
            })
            ->sort(function (array $a, array $b) {
                // Graded before ungraded, then best average first, then by name.
                return [$a['on20'] === null, -($a['on20'] ?? 0), self::name($a['player'])]
                    <=> [$b['on20'] === null, -($b['on20'] ?? 0), self::name($b['player'])];
            })
            ->values();
    }

    private static function name(Player $player): string
    {
        return mb_strtolower($player->lastname.' '.$player->firstname);
    }
}
