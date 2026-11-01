<?php

namespace App\Services\LiteraryReport\Sections;

use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use Carbon\CarbonImmutable;

/**
 * Training held in the period: sessions and attendance rate per branch and per category.
 * A late arrival still attended (owner decision 2026-11-01): rate = (present + late) ÷ expected.
 */
final class AttendanceSection implements Section
{
    private const ATTENDED = ['present', 'late'];

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
            // Sessions with no branch are club-wide: they get their own row without a rate (the category table includes them).
            $branches[] = ['name' => null, 'held' => (int) $byBranch[''], 'rate' => null];
        }

        $categories = collect($this->stats->categories($start, $end))
            ->filter(fn (array $row) => $row['held'] > 0)
            ->map(fn (array $row) => [
                'name' => $row['name'],
                'held' => (int) $row['held'],
                'expected' => (int) $row['expected'],
                'rate' => self::rate([$row]),
            ])->values()->all();

        return ['held' => (clone $held)->count(), 'branches' => $branches, 'categories' => $categories];
    }

    /** Attended marks (present + late) over expected marks across the given category rows, in %. */
    private static function rate(array $rows): ?float
    {
        $expected = array_sum(array_column($rows, 'expected'));
        $attended = 0;
        foreach ($rows as $row) {
            foreach (self::ATTENDED as $status) {
                $attended += $row['counts'][$status] ?? 0;
            }
        }

        return $expected > 0 ? round($attended / $expected * 100, 1) : null;
    }
}
