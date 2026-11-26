<?php

namespace App\Services\Attendance;

use App\Models\Category;
use App\Models\Player;
use App\Support\UiLang;
use Carbon\CarbonImmutable;

/**
 * The printed period sheet: for each category (and each of its training
 * groups), one MonthSheet page per month of [from, to], cut to the bounds,
 * then a summary of the period per player when more than one month has
 * sessions. Months after the current one are skipped: they hold no marks,
 * and building them would generate future sessions.
 */
final class PeriodSheet
{
    public const MAX_MONTHS = 13;

    public function __construct(
        private readonly MonthSheet $sheet,
        private readonly SheetTotals $totals,
    ) {}

    /**
     * $categoryId null = every category of the branch with sessions in the period, by id.
     * $groupId (with a category only): one training group, or MonthSheet::NO_GROUP; null or
     * unknown = every group entry of the category.
     *
     * @return list<array{category: Category, group: ?string, months: list<array>, summary: ?list<array>}>
     */
    public function build(string $from, string $to, ?int $branchId, ?int $categoryId = null, ?int $groupId = null): array
    {
        // Days after today hold no marks: cut them too, so they print no empty columns.
        $today = CarbonImmutable::now()->toDateString();
        if ($to > $today) {
            $to = $today;
        }
        $months = $from > $to ? [] : $this->months($from, $to);
        if ($months === []) {
            return [];
        }
        $categories = $categoryId !== null
            ? Category::whereKey($categoryId)->get()
            : Category::forBranch($branchId)->orderBy('id')->get();

        $sections = [];
        foreach ($categories as $category) {
            $entries = $this->groupEntries($category->id, $months, $branchId, $categoryId !== null ? $groupId : null);
            foreach ($entries as $entry) {
                $section = $this->section($category, $entry, $months, $from, $to, $branchId);
                if ($section !== null) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }

    /**
     * Every [year, month] touching [from, to], up to the current month.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function months(string $from, string $to): array
    {
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();
        $current = CarbonImmutable::now()->startOfMonth();
        if ($end->greaterThan($current)) {
            $end = $current;
        }

        $months = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = [$cursor->year, $cursor->month];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * The sections of one category: [id => null, name => null] when it has no groups,
     * else each group (by name), plus NO_GROUP when any month has sessions with no group.
     *
     * @param  list<array{0: int, 1: int}>  $months
     * @return list<array{id: ?int, name: ?string}>
     */
    private function groupEntries(int $categoryId, array $months, ?int $branchId, ?int $requested): array
    {
        $entries = [];
        foreach ($months as [$year, $month]) {
            foreach ($this->sheet->groupSwitch($categoryId, $year, $month, $branchId)['options'] as $option) {
                $entries[$option['id']] ??= ['id' => $option['id'], 'name' => $option['name'] ?? UiLang::get('att.group_whole')];
            }
        }
        if ($entries === []) {
            return [['id' => null, 'name' => null]];
        }
        // «Whole category» last, as on the month grid's switch.
        if (isset($entries[MonthSheet::NO_GROUP])) {
            $noGroup = $entries[MonthSheet::NO_GROUP];
            unset($entries[MonthSheet::NO_GROUP]);
            $entries[MonthSheet::NO_GROUP] = $noGroup;
        }
        if ($requested !== null && isset($entries[$requested])) {
            return [$entries[$requested]];
        }

        return array_values($entries);
    }

    /**
     * @param  array{id: ?int, name: ?string}  $entry
     * @param  list<array{0: int, 1: int}>  $months
     */
    private function section(Category $category, array $entry, array $months, string $from, string $to, ?int $branchId): ?array
    {
        $pages = [];
        $players = [];
        $perPlayer = [];
        foreach ($months as [$year, $month]) {
            $built = $this->sheet->build($category->id, $year, $month, $branchId, $entry['id'], $from, $to);
            if ($built['sessions']->isEmpty()) {
                continue;
            }
            $totals = [];
            foreach ($built['players'] as $player) {
                $totals[$player->id] = $this->totals->of($built['statuses'][$player->id] ?? []);
                $perPlayer[$player->id][] = $totals[$player->id];
                $players[$player->id] = $player;
            }
            $pages[] = [
                'month' => sprintf('%04d-%02d', $year, $month),
                'sessions' => $built['sessions'],
                'cells' => $built['cells'],
                'players' => $built['players'],
                'totals' => $totals,
                'guests' => $this->sheet->guestCategories($built['players'], $category->id, $branchId),
            ];
        }
        if ($pages === []) {
            return null;
        }

        $summary = null;
        if (count($pages) > 1) {
            $ordered = collect($players)->sortBy([['lastname', 'asc'], ['firstname', 'asc']])->values();
            $guests = $this->sheet->guestCategories($ordered, $category->id, $branchId);
            $summary = $ordered->map(fn (Player $p) => [
                'player' => $p,
                'guest' => $guests[$p->id] ?? null,
                'totals' => SheetTotals::sum($perPlayer[$p->id]),
            ])->all();
        }

        return ['category' => $category, 'group' => $entry['name'], 'months' => $pages, 'summary' => $summary];
    }
}
