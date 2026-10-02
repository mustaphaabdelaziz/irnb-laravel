<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads list filters that may hold several values.
 *
 * The list pages send a multi-select as `key[]=a&key[]=b` (Inertia) or
 * `key[0]=a` (Ziggy export links); old links, bookmarks and dashboard
 * drill-downs still send a single `key=a`. Every shape reads as one clean
 * list here, so each controller only decides what the values mean.
 */
final class ListFilter
{
    /** No real filter needs more; caps the size of the generated SQL. */
    public const MAX_VALUES = 100;

    /**
     * The filter as a list of trimmed, non-empty, unique strings.
     *
     * @return list<string>
     */
    public static function values(Request $request, string $key): array
    {
        $raw = $request->input($key);
        $raw = is_array($raw) ? $raw : [$raw];

        $values = [];
        foreach ($raw as $value) {
            // Nested arrays, objects and booleans are never a valid choice.
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }

            if (count($values) >= self::MAX_VALUES) {
                break;
            }
        }

        return $values;
    }

    /**
     * The filter as a list of positive integer ids; anything else is dropped.
     *
     * @return list<int>
     */
    public static function ids(Request $request, string $key): array
    {
        return array_values(array_map(
            'intval',
            array_filter(self::values($request, $key), fn (string $value) => ctype_digit($value) && (int) $value > 0),
        ));
    }

    /**
     * The `filters` prop echoed back to the page: multi-select keys as lists
     * (only when something is selected), single-value keys as sent.
     *
     * @param  list<string>  $multi
     * @param  list<string>  $single
     * @return array<string, mixed>
     */
    public static function echo(Request $request, array $multi, array $single = []): array
    {
        $filters = $request->only($single);

        foreach ($multi as $key) {
            $values = self::values($request, $key);
            if ($values !== []) {
                $filters[$key] = $values;
            }
        }

        return $filters;
    }
}
