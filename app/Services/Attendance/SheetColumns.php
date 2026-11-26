<?php

namespace App\Services\Attendance;

use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The session columns of the printed month and period sheets, and how they
 * split into parts of at most MAX columns (a new page each).
 */
final class SheetColumns
{
    /** Session columns per printed table; more continue in a second part on a new page. */
    public const MAX = 16;

    /** Blank columns after the month's last session, for a session added on the day, when they fit. */
    public const BLANK = 2;

    /**
     * @param  Collection<int, TrainingSession>  $sessions
     * @return list<array{id: int, day: string, date: string, time: string, kind: string, title: ?string}>
     */
    public static function of(Collection $sessions, string $locale): array
    {
        return $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'day' => CarbonImmutable::createFromFormat('!Y-m-d', $s->date)->locale($locale)->translatedFormat('D'),
            'date' => substr($s->date, 8, 2).'/'.substr($s->date, 5, 2),
            'time' => $s->start_time,
            'kind' => $s->kind->value,
            'title' => $s->title ? Str::limit(self::wrapLongWords($s->title), 24) : null,
        ])->values()->all();
    }

    /**
     * $blankColumns false = a record (period sheet): never any blank column.
     *
     * @param  list<array>  $columns
     * @return list<array{columns: list<array>, from: int, to: int, blank: int}>
     */
    public static function parts(array $columns, bool $blankColumns = true): array
    {
        $chunks = array_chunk($columns, self::MAX);
        $parts = [];
        foreach ($chunks as $i => $chunk) {
            $last = $i === count($chunks) - 1;
            $parts[] = [
                'columns' => $chunk,
                'from' => $i * self::MAX + 1,
                'to' => $i * self::MAX + count($chunk),
                'blank' => $blankColumns && $last && count($chunk) + self::BLANK <= self::MAX ? self::BLANK : 0,
            ];
        }

        return $parts;
    }

    /**
     * Breaks any word over $width characters into $width-character chunks
     * joined by spaces, so mPDF can wrap a long session title instead of
     * widening the whole column. mb_str_split() counts characters, not bytes,
     * so it never cuts a multibyte (e.g. Arabic) character.
     */
    public static function wrapLongWords(string $text, int $width = 10): string
    {
        return implode(' ', array_map(
            fn (string $word) => mb_strlen($word) > $width ? implode(' ', mb_str_split($word, $width)) : $word,
            explode(' ', $text)
        ));
    }
}
