<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Club-wide attendance statistics (`attendance.stats`, view): a period (this
 * month by default, the season, or a custom range) and an optional category.
 * Every number comes from AttendanceStats.
 */
class AttendanceStatsController extends Controller
{
    public function __construct(private readonly AttendanceStats $stats) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Attendance/Stats', [
            ...$this->data($request),
            'attendanceCodes' => AttendanceSettings::codes(),
        ]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $period = ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        $rows = $this->stats->players($from, $to, $categoryId);
        $players = $this->withNames($rows);
        $sessions = $this->stats->sessionsByMonth($from, $to, $categoryId);

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'totals' => [
                ...$this->stats->summarize($rows),
                'held' => array_sum($sessions['held']),
                'cancelled' => array_sum($sessions['cancelled']),
            ],
            'monthly' => $this->stats->monthly($from, $to, $categoryId),
            'sessionsByMonth' => $sessions,
            'categoryRows' => $this->stats->categories($from, $to),
            'players' => $players,
            'ranking' => AttendanceStats::ranking($players),
        ];
    }

    /** Adds each player's name, membership id and current category; sorted by name. One query for all. */
    private function withNames(array $rows): array
    {
        $players = Player::with('category')
            ->whereIn('id', array_keys($rows))
            ->get(['id', 'firstname', 'lastname', 'nickname', 'father', 'grandfather', 'membership_id', 'category_id'])
            ->keyBy('id');

        return collect($rows)
            ->map(function (array $row) use ($players) {
                $player = $players->get($row['player_id']);

                return [
                    ...$row,
                    'name' => $player?->fullname ?? '#'.$row['player_id'],
                    'membership_id' => $player?->membership_id,
                    'category' => $player?->category?->localized_name,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
