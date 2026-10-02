<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which (category, month) pairs SessionGenerator has already generated from
 * the current inputs. Generation reads schedules, club closures, moved
 * sessions and joint pre-season sessions, and fills every free slot; any
 * change to those must forget the affected marks so the next calendar view
 * generates the month again (freed slots come back, as before the marks).
 */
final class GenerationMarks
{
    private const TABLE = 'session_generation_marks';

    public static function has(int $categoryId, string $month): bool
    {
        return DB::table(self::TABLE)->where('category_id', $categoryId)->where('month', $month)->exists();
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<string, true> keyed "categoryId:Y-m"
     */
    public static function marked(array $categoryIds, string $fromMonth, string $toMonth): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return DB::table(self::TABLE)
            ->whereIn('category_id', $categoryIds)
            ->whereBetween('month', [$fromMonth, $toMonth])
            ->get(['category_id', 'month'])
            ->mapWithKeys(fn ($row) => ["{$row->category_id}:{$row->month}" => true])
            ->all();
    }

    public static function mark(int $categoryId, string $month): void
    {
        DB::table(self::TABLE)->insertOrIgnore(['category_id' => $categoryId, 'month' => $month, 'created_at' => now()]);
    }

    /** Every month of these categories (a schedule changed). */
    public static function forgetCategories(int ...$categoryIds): void
    {
        DB::table(self::TABLE)->whereIn('category_id', array_unique($categoryIds))->delete();
    }

    /** Every category's months overlapping the 'Y-m-d' range (a closure changed). */
    public static function forgetDates(string $from, string $to): void
    {
        DB::table(self::TABLE)->whereBetween('month', [substr($from, 0, 7), substr($to, 0, 7)])->delete();
    }

    /**
     * The months of these 'Y-m-d' dates, for these categories (a session
     * was added, removed, moved or changed categories).
     *
     * @param  array<int, int|string|null>  $categoryIds
     * @param  array<int, string|null>  $dates
     */
    public static function forgetSlots(array $categoryIds, array $dates): void
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $months = array_values(array_unique(array_map(fn (string $d) => substr($d, 0, 7), array_filter($dates))));
        if ($categoryIds === [] || $months === []) {
            return;
        }

        DB::table(self::TABLE)->whereIn('category_id', $categoryIds)->whereIn('month', $months)->delete();
    }

    /** @return array<int, string> 'Y-m' of every month from $from to $to ('Y-m-d'), inclusive */
    public static function months(string $from, string $to): array
    {
        $months = [];
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();
        for ($m = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $m->lessThanOrEqualTo($last); $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }
}
