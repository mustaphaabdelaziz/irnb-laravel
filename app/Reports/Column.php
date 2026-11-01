<?php

namespace App\Reports;

/**
 * One column of a report table. `label` is an i18n key; `type` drives
 * alignment and formatting on screen, in the PDF and in the spreadsheet
 * (money and numbers stay numeric there); `total` adds it to the totals row.
 */
final class Column
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const DATE = 'date';

    public const PERCENT = 'percent';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly bool $total = false,
    ) {}

    public function numeric(): bool
    {
        return in_array($this->type, [self::NUMBER, self::MONEY, self::PERCENT], true);
    }

    /** The cell as printed text (PDF). */
    public function display(mixed $value, string $currency = ''): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            self::MONEY => number_format((float) $value, 2).($currency !== '' ? ' '.$currency : ''),
            self::NUMBER => is_float($value) ? number_format($value, 1) : number_format((int) $value),
            self::PERCENT => number_format((float) $value, 1).'%',
            default => (string) $value,
        };
    }

    /** The cell for a spreadsheet: numbers stay numbers. */
    public function cell(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->numeric() ? (is_float($value) || $this->type !== self::NUMBER ? (float) $value : (int) $value) : (string) $value;
    }

    /** @return array{key: string, label: string, type: string, total: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type, 'total' => $this->total];
    }
}
