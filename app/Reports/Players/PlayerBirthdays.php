<?php

namespace App\Reports\Players;

use App\Models\Player;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Active players whose birthday falls in a month, by day, with the age they turn this year. */
class PlayerBirthdays extends Report
{
    use FiltersPlayers;

    public function key(): string
    {
        return 'birthdays';
    }

    public function category(): string
    {
        return 'players';
    }

    public function module(): string
    {
        return 'players';
    }

    public function icon(): string
    {
        return 'calendar';
    }

    public function filters(): array
    {
        return [
            Filter::select('month', 'reports.filter.month', array_map(
                fn (int $m) => ['value' => $m, 'label' => Carbon::create(2000, $m, 1)->locale(app()->getLocale())->translatedFormat('F')], range(1, 12)), (int) now()->month),
            $this->categoryFilter(),
            $this->branchFilter(),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('day', 'reports.col.day', Column::NUMBER),
            new Column('name', 'col.full_name'),
            new Column('birthdate', 'col.birthdate', Column::DATE),
            new Column('turns', 'reports.col.turns', Column::NUMBER),
            new Column('category', 'col.category'),
            new Column('phone', 'col.phone'),
        ];
    }

    public function sortable(): array
    {
        return ['day' => 'day', 'name' => 'name', 'turns' => 'turns'];
    }

    public function defaultSort(): array
    {
        return ['day', 'asc'];
    }

    public function landscape(): bool
    {
        return false;
    }

    /** Filtered in PHP: the month of a date has no portable SQL form across SQLite and MySQL. */
    public function rows(ReportFilters $filters): Collection
    {
        $month = (int) $filters->get('month');
        $year = (int) now()->year;

        $query = Player::query()->where('archived', false)->whereNotNull('birthdate')->with('category');
        $this->applyCategoryBranch($query, $filters);

        return $query->get()
            ->filter(fn (Player $p) => (int) $p->birthdate->month === $month)
            ->map(fn (Player $p) => [
                'day' => (int) $p->birthdate->day,
                'name' => $p->fullname,
                'birthdate' => $p->birthdate->format('Y-m-d'),
                'turns' => $year - (int) $p->birthdate->year,
                'category' => $p->category?->localized_name,
                'phone' => collect($p->phones ?? [])->filter()->first(),
            ])
            ->sortBy('day')
            ->values();
    }

    public function summary(ReportFilters $filters): array
    {
        return [['label' => 'reports.kpi.players', 'value' => $this->rows($filters)->count(), 'format' => 'number']];
    }
}
