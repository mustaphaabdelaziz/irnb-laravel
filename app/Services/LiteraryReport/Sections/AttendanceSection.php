<?php

namespace App\Services\LiteraryReport\Sections;

use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use Carbon\CarbonImmutable;

/** Training held in the period: sessions and attendance rate per branch and per category. */
final class AttendanceSection implements Section
{
    public function __construct(private readonly AttendanceStats $stats) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->toDateString(), $to->toDateString()];

        $held = TrainingSession::query()->where('state', SessionState::Held)->whereBetween('date', [$start, $end]);
        $byBranch = (clone $held)->selectRaw('branch_id, COUNT(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');

        $branches = Branch::query()->whereIn('id', $byBranch->keys()->filter())->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])
            ->sortBy('localized_name')
            ->map(fn (Branch $branch) => [
                'name' => $branch->localized_name,
                'held' => (int) $byBranch[$branch->id],
                'rate' => self::rate($this->stats->categories($start, $end, $branch->id)),
            ])->values()->all();
        if (isset($byBranch[''])) {
            // Sessions with no branch are club-wide; their rate is folded into the totals only.
            $branches[] = ['name' => null, 'held' => (int) $byBranch[''], 'rate' => null];
        }

        $categories = collect($this->stats->categories($start, $end))
            ->filter(fn (array $row) => $row['held'] > 0)
            ->map(fn (array $row) => [
                'name' => $row['name'],
                'held' => (int) $row['held'],
                'expected' => (int) $row['expected'],
                'rate' => $row['pct']['present'] ?? null,
            ])->values()->all();

        return ['held' => (clone $held)->count(), 'branches' => $branches, 'categories' => $categories];
    }

    /** Present marks over expected marks across the given category rows, in %. */
    private static function rate(array $rows): ?float
    {
        $expected = array_sum(array_column($rows, 'expected'));
        $present = array_sum(array_map(fn (array $row) => $row['counts']['present'] ?? 0, $rows));

        return $expected > 0 ? round($present / $expected * 100, 1) : null;
    }
}
