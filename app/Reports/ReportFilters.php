<?php

namespace App\Reports;

use App\Support\UiLang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A report's filter values, validated against the filters the report
 * declares, plus the table's sort, direction and page size. Unknown option
 * values, impossible dates, a reversed date range and unlisted sort columns
 * are refused, never silently ignored.
 */
final class ReportFilters
{
    public const PER_PAGE = [25, 50, 100];

    /**
     * @param  array<string, mixed>  $values
     * @param  list<Filter>  $defs
     */
    private function __construct(
        private readonly array $values,
        private readonly array $defs,
        public readonly ?string $sort,
        public readonly string $dir,
        public readonly int $perPage,
    ) {}

    /** @throws ValidationException */
    public static function fromRequest(Request $request, Report $report): self
    {
        $defs = $report->filters();
        $rules = [
            'sort' => ['nullable', 'string', Rule::in(array_keys($report->sortable()))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'format' => ['nullable', Rule::in(['xlsx', 'csv', 'pdf'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
        foreach ($defs as $def) {
            $rules += $def->rules();
        }

        $data = Validator::make($request->query(), $rules)->validate();

        $values = [];
        foreach ($defs as $def) {
            $values[$def->key] = $def->cast($data[$def->key] ?? null);
        }

        if (isset($values['from'], $values['to']) && $values['to'] < $values['from']) {
            throw ValidationException::withMessages(['to' => [UiLang::get('reports.error.date_range')]]);
        }

        [$defaultSort, $defaultDir] = $report->defaultSort();

        return new self(
            $values,
            $defs,
            $data['sort'] ?? $defaultSort,
            $data['dir'] ?? ($data['sort'] ?? null ? 'asc' : $defaultDir),
            (int) ($data['per_page'] ?? self::PER_PAGE[0]),
        );
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** Set to something: not null, not an empty list. */
    public function filled(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    /** What the viewer echoes back into its form. @return array<string, mixed> */
    public function values(): array
    {
        return [...$this->values, 'sort' => $this->sort, 'dir' => $this->dir, 'per_page' => $this->perPage];
    }

    /**
     * The filters that narrow the report, as printed under its title:
     * label => value(s), option values replaced by their labels.
     *
     * @return list<array{label: string, value: string}>
     */
    public function describe(): array
    {
        $lines = [];
        foreach ($this->defs as $def) {
            if (! $this->filled($def->key)) {
                continue;
            }
            $value = $this->get($def->key);
            $text = match ($def->type) {
                Filter::MULTI => implode(', ', array_map(fn ($v) => $def->optionLabel($v), $value)),
                Filter::SELECT => $def->optionLabel($value),
                default => (string) $value,
            };
            $lines[] = ['label' => UiLang::get($def->label), 'value' => $text];
        }

        return $lines;
    }
}
