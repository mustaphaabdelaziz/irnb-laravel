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
 *
 * Every forget also bumps one version counter. A generation captures the
 * version before reading its inputs and marks its month only if it is still
 * the same: a change committed meanwhile (whose forget found no mark yet to
 * delete) leaves the month unmarked, so the next view generates it again.
 */
final class GenerationMarks
{
    private const TABLE = 'session_generation_marks';

    private const VERSION_TABLE = 'session_generation_version';

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

    /** The current input version; capture it before reading the generator's inputs. */
    public static function version(): int
    {
        return (int) DB::table(self::VERSION_TABLE)->where('id', 1)->value('version');
    }

    /**
     * Marks the month if no input changed since $version was read. Call it
     * inside the generation's write transaction: the row lock (MySQL) or the
     * write lock (SQLite) orders it against a concurrent forget.
     */
    public static function mark(int $categoryId, string $month, int $version): bool
    {
        $current = (int) DB::table(self::VERSION_TABLE)->where('id', 1)->lockForUpdate()->value('version');
        if ($current !== $version) {
            return false;
        }

        DB::table(self::TABLE)->insertOrIgnore(['category_id' => $categoryId, 'month' => $month, 'created_at' => now()]);

        return true;
    }

    /** Every mark, or every mark of one category (attendance:regenerate). */
    public static function forgetAll(?int $categoryId = null): int
    {
        self::bump();

        return DB::table(self::TABLE)->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))->delete();
    }

    /** Every month of these categories (a schedule changed). */
    public static function forgetCategories(int ...$categoryIds): void
    {
        self::bump();
        DB::table(self::TABLE)->whereIn('category_id', array_unique($categoryIds))->delete();
    }

    /** Every category's months overlapping the 'Y-m-d' range (a closure changed). */
    public static function forgetDates(string $from, string $to): void
    {
        self::bump();
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

        self::bump();
        DB::table(self::TABLE)->whereIn('category_id', $categoryIds)->whereIn('month', $months)->delete();
    }

    /** Bumped before the marks are deleted, so a generation marking in between is caught either way. */
    private static function bump(): void
    {
        if (DB::table(self::VERSION_TABLE)->where('id', 1)->increment('version') === 0) {
            DB::table(self::VERSION_TABLE)->insertOrIgnore(['id' => 1, 'version' => 1]);
        }
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
