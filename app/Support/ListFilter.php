<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reads list filters that may hold several values.
 *
 * The list pages send a multi-select as `key[]=a&key[]=b` (Inertia) or
 * `key[0]=a` (Ziggy export links); old links, bookmarks and dashboard
 * drill-downs still send a single `key=a`. Every shape reads as one clean
 * list here, so each controller only decides what the values mean.
 *
 * A spec says which values a filter can apply (see get()):
 *   ListFilter::IDS          positive integer ids
 *   ListFilter::TEXT         any non-empty text (e.g. family names)
 *   'ids|none'               ids plus the listed special words
 *   '/^regex$/'              values matching the pattern
 *   ['a', 'b']               only these values
 */
final class ListFilter
{
    /**
     * More values than this is refused (422), never silently cut: a cut list
     * would quietly widen or change the result. The page collapses "every
     * option checked" to no filter, so a real selection stays far below it.
     *
     * Kept under PHP's max_input_vars (1000 by default): past that limit PHP
     * itself drops the extra query values before the app sees them, so a cap
     * of 1000 could never be detected. 500 leaves room for the other filters.
     */
    public const MAX_VALUES = 500;

    public const IDS = 'ids';

    public const TEXT = 'text';

    /**
     * The filter as a list of trimmed, non-empty, unique strings.
     *
     * @return list<string>
     *
     * @throws ValidationException when more than MAX_VALUES values are sent
     */
    public static function values(Request $request, string $key): array
    {
        $raw = $request->input($key);
        $raw = is_array($raw) ? $raw : [$raw];

        if (count($raw) > self::MAX_VALUES) {
            throw ValidationException::withMessages([
                $key => trans('validation.max.array', ['attribute' => $key, 'max' => self::MAX_VALUES]),
            ]);
        }

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
        return array_values(array_map('intval', array_filter(self::values($request, $key), self::isId(...))));
    }

    /**
     * The values of the filter that its spec accepts, in request order.
     *
     * @param  string|list<string>  $spec
     * @return list<string>
     */
    public static function get(Request $request, string $key, string|array $spec): array
    {
        $values = self::values($request, $key);

        if (is_array($spec)) {
            return array_values(array_filter($values, fn (string $v) => in_array($v, $spec, true)));
        }

        if ($spec === self::TEXT) {
            return $values;
        }

        if (str_starts_with($spec, '/')) {
            return array_values(array_filter($values, fn (string $v) => preg_match($spec, $v) === 1));
        }

        // 'ids' or 'ids|word|word'
        $extras = array_slice(explode('|', $spec), 1);

        return array_values(array_filter($values, fn (string $v) => self::isId($v) || in_array($v, $extras, true)));
    }

    /**
     * The `filters` prop echoed back to the page: each multi-select key as the
     * list of values it actually applies (only when there is one), so a stale
     * or junk value never shows as "selected" without filtering anything.
     * Single-value keys are echoed as sent.
     *
     * @param  array<string, string|list<string>>  $specs  key => spec
     * @param  list<string>  $single
     * @return array<string, mixed>
     */
    public static function echo(Request $request, array $specs, array $single = []): array
    {
        $filters = $request->only($single);

        foreach ($specs as $key => $spec) {
            $values = self::get($request, $key, $spec);
            if ($values !== []) {
                $filters[$key] = $values;
            }
        }

        return $filters;
    }

    private static function isId(string $value): bool
    {
        return ctype_digit($value) && (int) $value > 0;
    }
}
