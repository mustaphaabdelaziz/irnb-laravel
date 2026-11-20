<?php

namespace App\Reports\Players;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\RegistrationStatusChange;
use App\Reports\Column;
use App\Reports\Concerns\FiltersPlayers;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Services\Player\RegistrationDisplay;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who joined, who left and who came back. Joins follow the join year on the player
 * record (a year, not a date). Departures and returns follow the status history
 * (MovementEvents), so a player who came back still counts in the year he left.
 */
class PlayerMovement extends Report
{
    use FiltersPlayers;

    private ?int $rowBranch = null;

    private ?RegistrationDisplay $display = null;

    /** @var array<int, array{left_at: ?string, returned_at: ?string}> the dates each listed player was found by */
    private array $dates = [];

    public function key(): string
    {
        return 'movement';
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
        return 'players';
    }

    public function filters(): array
    {
        $joinYears = Player::query()->whereNotNull('join_year')->distinct()->pluck('join_year')->map(fn ($y) => (int) $y);
        $leftYears = BranchRegistration::query()->whereNotNull('left_at')->pluck('left_at')
            ->merge(Player::query()->whereNotNull('left_at')->pluck('left_at'))
            ->merge(RegistrationStatusChange::query()->pluck('changed_on'))
            ->map(fn ($d) => (int) substr((string) $d, 0, 4));
        $years = $joinYears->merge($leftYears)->push((int) now()->year)->unique()->sortDesc()->values();

        return [
            Filter::select('movement', 'reports.filter.movement', [
                ['value' => 'joined', 'label' => UiLang::get('reports.movement.joined')],
                ['value' => 'left', 'label' => UiLang::get('reports.movement.left')],
                ['value' => 'returned', 'label' => UiLang::get('reports.movement.returned')],
            ], 'joined'),
            Filter::select('year', 'reports.filter.year', $years->map(fn (int $y) => ['value' => $y, 'label' => (string) $y])->all(), (int) now()->year),
            $this->categoryFilter(),
            $this->branchFilter(),
        ];
    }

    public function columns(): array
    {
        return [
            new Column('membership_id', 'col.membership_id'),
            new Column('name', 'col.full_name'),
            new Column('category', 'col.category'),
            new Column('branches', 'col.branches'),
            new Column('join_year', 'col.join_year', Column::NUMBER),
            new Column('left_at', 'reports.col.left_at', Column::DATE),
            new Column('returned_at', 'reports.col.returned_at', Column::DATE),
            new Column('status', 'col.status'),
        ];
    }

    public function sortable(): array
    {
        return ['membership_id' => 'membership_id', 'name' => 'lastname', 'join_year' => 'join_year', 'left_at' => 'left_at'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function rows(ReportFilters $filters): Builder
    {
        $this->rowBranch = $this->shownBranch($filters);
        $this->display = RegistrationDisplay::make();
        $movement = (string) $filters->get('movement');
        $this->dates = $movement === 'joined' ? [] : $this->datesFor($filters, $movement);

        return $this->base($filters, $movement, $this->dates)
            ->with(['category', 'status', 'branches'])
            ->when($this->rowBranch !== null, fn ($q) => $q->with(['registrations.category', 'registrations.status']));
    }

    /** @param Player $record */
    public function row(mixed $record): array
    {
        $this->display->overlay($record, $this->rowBranch);
        $found = $this->dates[$record->id] ?? null;

        return [
            'membership_id' => $record->membership_id,
            'name' => $record->fullname,
            'category' => $record->category?->localized_name,
            'branches' => $record->branches->map(fn (Branch $b) => $b->localized_name)->implode(', '),
            'join_year' => $record->join_year,
            'left_at' => $found !== null ? $found['left_at'] : $record->left_at?->format('Y-m-d'),
            'returned_at' => $found['returned_at'] ?? null,
            'status' => $record->status?->localized_name,
        ];
    }

    public function summary(ReportFilters $filters): array
    {
        return [
            ['label' => 'reports.movement.joined', 'value' => $this->base($filters, 'joined')->count(), 'format' => 'number'],
            ['label' => 'reports.movement.left', 'value' => $this->base($filters, 'left')->count(), 'format' => 'number'],
            ['label' => 'reports.movement.returned', 'value' => $this->base($filters, 'returned')->count(), 'format' => 'number'],
        ];
    }

    public function charts(ReportFilters $filters): array
    {
        $joined = $this->applyCategoryBranch(Player::query()->whereNotNull('join_year'), $filters)->toBase()
            ->groupBy('join_year')->orderBy('join_year')->selectRaw('join_year as y, COUNT(*) as c')->get();

        $series = fn (Collection $rows) => $rows->map(fn ($r) => ['key' => (string) $r->y, 'label' => (string) $r->y, 'count' => (int) $r->c])->take(-10)->values()->all();

        return [
            ['type' => 'bar', 'title' => 'reports.chart.joins_by_year', 'stats' => $series($joined)],
            ['type' => 'bar', 'title' => 'reports.chart.leavers_by_year', 'stats' => $series($this->leftByYear($filters))],
        ];
    }

    /** Distinct leavers per year. Grouped in PHP: a year taken from a date has no form all three databases accept. */
    private function leftByYear(ReportFilters $filters): Collection
    {
        $events = $this->allowed($filters, app(MovementEvents::class)->departures(...$this->scope($filters)));

        return $events->groupBy(fn ($e) => substr($e->on, 0, 4))
            ->map(fn (Collection $items) => $items->pluck('player_id')->unique()->count())
            ->sortKeys()
            ->map(fn (int $count, $year) => (object) ['y' => (string) $year, 'c' => $count])
            ->values();
    }

    /**
     * The players a movement lists in the filter's year, with the dates they were found by.
     * Left: the year's last departure, then the first return on or after it.
     * Returned: the year's last return, and the last departure on or before it.
     *
     * @return array<int, array{left_at: ?string, returned_at: ?string}>
     */
    private function datesFor(ReportFilters $filters, string $movement): array
    {
        $year = (int) $filters->get('year');
        $events = app(MovementEvents::class);
        $departures = $this->allowed($filters, $events->departures(...$this->scope($filters)));
        $returns = $this->allowed($filters, $events->returns(...$this->scope($filters)));
        $inYear = fn ($e) => (int) substr($e->on, 0, 4) === $year;

        if ($movement === 'left') {
            return $departures->filter($inYear)->groupBy('player_id')
                ->map(function (Collection $items, $playerId) use ($returns) {
                    $on = $items->max('on');

                    return ['left_at' => $on, 'returned_at' => $returns->where('player_id', (int) $playerId)->filter(fn ($r) => $r->on >= $on)->min('on')];
                })->all();
        }

        return $returns->filter($inYear)->groupBy('player_id')
            ->map(function (Collection $items, $playerId) use ($departures) {
                $on = $items->max('on');

                return ['left_at' => $departures->where('player_id', (int) $playerId)->filter(fn ($d) => $d->on <= $on)->max('on'), 'returned_at' => $on];
            })->all();
    }

    /** @return array{0: ?array, 1: ?array} branches and categories for MovementEvents */
    private function scope(ReportFilters $filters): array
    {
        return [
            $filters->filled('branch_id') ? array_map('intval', (array) $filters->get('branch_id')) : null,
            $filters->filled('category_id') ? array_map('intval', (array) $filters->get('category_id')) : null,
        ];
    }

    /** Club-wide, the category filter follows the shared list rule on the players found. */
    private function allowed(ReportFilters $filters, Collection $events): Collection
    {
        if ($filters->filled('branch_id') || ! $filters->filled('category_id') || $events->isEmpty()) {
            return $events;
        }
        $kept = $this->applyCategoryBranch(Player::query()->whereIn('id', $events->pluck('player_id')->unique()->all()), $filters)
            ->pluck('id')->flip();

        return $events->filter(fn ($e) => $kept->has($e->player_id))->values();
    }

    /**
     * @param  array<int, mixed>|null  $dates  already computed by rows()
     * @return Builder<Player>
     */
    private function base(ReportFilters $filters, string $movement, ?array $dates = null): Builder
    {
        if ($movement === 'joined') {
            return $this->applyCategoryBranch(Player::query()->where('join_year', (int) $filters->get('year')), $filters);
        }

        // Events already carry the branch and category filters.
        return Player::query()->whereIn('id', array_keys($dates ?? $this->datesFor($filters, $movement)));
    }
}
