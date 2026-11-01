<?php

namespace App\Reports;

use Illuminate\Validation\Rule;

/**
 * One control in a report's filter form. The report declares it, the viewer
 * renders it from toArray(), and ReportFilters validates the query string
 * against rules() — the server never trusts what the form sent.
 */
final class Filter
{
    public const SELECT = 'select';

    public const MULTI = 'multi';

    public const DATE = 'date';

    public const NUMBER = 'number';

    /** @param list<array{value: int|string, label: string}> $options */
    private function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly array $options = [],
        public readonly mixed $default = null,
        public readonly int $min = 0,
        public readonly int $max = 0,
    ) {}

    /** One value out of $options; $default applies when the parameter is absent. */
    public static function select(string $key, string $label, array $options, mixed $default = null): self
    {
        return new self($key, self::SELECT, $label, array_values($options), $default);
    }

    /** Any number of values out of $options (OR within the filter). */
    public static function multi(string $key, string $label, array $options): self
    {
        return new self($key, self::MULTI, $label, array_values($options), []);
    }

    /** A Y-m-d date. */
    public static function date(string $key, string $label): self
    {
        return new self($key, self::DATE, $label);
    }

    public static function number(string $key, string $label, int $min = 0, int $max = 150): self
    {
        return new self($key, self::NUMBER, $label, [], null, $min, $max);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $values = array_map(fn (array $o) => (string) $o['value'], $this->options);

        return match ($this->type) {
            self::SELECT => [$this->key => ['nullable', Rule::in($values)]],
            self::MULTI => [$this->key => ['nullable', 'array'], $this->key.'.*' => [Rule::in($values)]],
            self::DATE => [$this->key => ['nullable', 'date_format:Y-m-d']],
            self::NUMBER => [$this->key => ['nullable', 'integer', 'min:'.$this->min, 'max:'.$this->max]],
        };
    }

    /** The validated query value in its PHP shape, or the default when absent. */
    public function cast(mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return $this->default;
        }

        return match ($this->type) {
            self::MULTI => array_values(array_map(fn ($v) => $this->typed($v), (array) $raw)),
            self::NUMBER => (int) $raw,
            self::SELECT => $this->typed($raw),
            default => (string) $raw,
        };
    }

    /** The label of an option value, for the "applied filters" line. */
    public function optionLabel(mixed $value): string
    {
        foreach ($this->options as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['label'];
            }
        }

        return (string) $value;
    }

    /** @return array{key: string, type: string, label: string, options: list<array{value: int|string, label: string}>, min: int, max: int} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
        ];
    }

    /** Option values keep their declared type (ids stay ints), so reports compare strictly. */
    private function typed(mixed $value): int|string
    {
        foreach ($this->options as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['value'];
            }
        }

        return (string) $value;
    }
}
