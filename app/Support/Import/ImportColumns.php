<?php

namespace App\Support\Import;

use App\Support\NameNormalizer;
use App\Support\UiLang;

/**
 * Finds an import's columns by header text in ar / fr / en (or an older
 * template's header), falling back to position — so a template downloaded in
 * French imports fine for an Arabic user, and old files keep working.
 */
final class ImportColumns
{
    private const LOCALES = ['ar', 'fr', 'en'];

    private const SCAN_ROWS = 5;

    /** @param  list<array{key:string, label:string, hint?:string, legacy?:list<string>}>  $columns */
    public function __construct(private readonly array $columns) {}

    /**
     * Template headers: "Label (hint)" when the column has a hint.
     *
     * @return list<string>
     */
    public function headers(?string $locale = null): array
    {
        return array_map(fn (array $c) => self::header($c, $locale), $this->columns);
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @return array{0:int, 1:array<string,int>}
     */
    public function locate(array $rows): array
    {
        $needed = count($this->columns) > 1 ? 2 : 1;

        foreach (array_slice($rows, 0, self::SCAN_ROWS, true) as $i => $row) {
            $matched = $this->match($row);
            if (count($matched) >= $needed) {
                return [$i, $this->withPositions($matched, $row)];
            }
        }

        return [0, $this->withPositions([], null)];
    }

    /**
     * @param  array<string, string>  $codes  code => i18n label key
     */
    public static function value(?string $raw, array $codes): ?string
    {
        $needle = NameNormalizer::key($raw);
        if ($needle === '') {
            return null;
        }
        foreach ($codes as $code => $labelKey) {
            if (NameNormalizer::key((string) $code) === $needle) {
                return (string) $code;
            }
            foreach (self::LOCALES as $locale) {
                if (NameNormalizer::key(UiLang::get($labelKey, null, $locale)) === $needle) {
                    return (string) $code;
                }
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function match(array $row): array
    {
        $known = [];
        foreach ($this->columns as $c) {
            $texts = $c['legacy'] ?? [];
            foreach (self::LOCALES as $locale) {
                $texts[] = UiLang::get($c['label'], null, $locale);
                // The hinted header too, so it matches exactly — ahead of a sibling
                // whose header only looks the same once hints are stripped.
                $texts[] = self::header($c, $locale);
            }
            $known[$c['key']] = [
                'exact' => array_unique(array_map(NameNormalizer::key(...), $texts)),
                'stripped' => array_unique(array_map(fn ($t) => NameNormalizer::key(self::strip($t)), $texts)),
            ];
        }

        $map = [];
        foreach ($row as $index => $cell) {
            if (! is_string($cell) || trim($cell) === '') {
                continue;
            }
            foreach (['exact' => NameNormalizer::key($cell), 'stripped' => NameNormalizer::key(self::strip($cell))] as $kind => $needle) {
                foreach ($this->columns as $c) {
                    if (! isset($map[$c['key']]) && in_array($needle, $known[$c['key']][$kind], true)) {
                        $map[$c['key']] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Matched columns keep their index; an unmatched one falls back to its
     * template position when that position is free. With a header row, the
     * position must also have an empty header — a named header there is some
     * other column (e.g. an export's "Status" where the template has "Price").
     * Without a header row (legacy positional files) any free position goes.
     *
     * @param  array<string, int>  $matched
     * @param  list<string|null>|null  $header  the detected header row, or null
     */
    private function withPositions(array $matched, ?array $header): array
    {
        $taken = array_flip($matched);
        $map = [];
        foreach ($this->columns as $position => $c) {
            if (isset($matched[$c['key']])) {
                $map[$c['key']] = $matched[$c['key']];
            } elseif (! isset($taken[$position]) && ($header === null || trim((string) ($header[$position] ?? '')) === '')) {
                $map[$c['key']] = $position;
            }
        }

        return $map;
    }

    /** "Label (hint)" in $locale, or just the label when the column has no hint. */
    private static function header(array $column, ?string $locale): string
    {
        $label = UiLang::get($column['label'], null, $locale);

        return isset($column['hint']) ? $label.' ('.UiLang::get($column['hint'], null, $locale).')' : $label;
    }

    private static function strip(string $text): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $text));
    }
}
