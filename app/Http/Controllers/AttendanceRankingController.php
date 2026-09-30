<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\PlayerNames;
use App\Services\Pdf\PdfService;
use App\Support\Season;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One category's attendance ranking for a month or a season, and (Task 9)
 * the certificates printed from it. Players are ranked by
 * AttendanceStats::ranked() — the statistics page's rules — over the marks
 * attributed to the category, as the statistics page does when filtered on
 * it. Only reads (attendance/view, see config/permissions.php).
 */
class AttendanceRankingController extends Controller
{
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PlayerNames $names,
        private readonly PdfService $pdf,
    ) {}

    public function index(Request $request): Response
    {
        $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
            ->values()->all();
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : ($categories[0]['id'] ?? null);
        $period = $this->period($request);
        ['rows' => $rows, 'unranked' => $unranked] = $categoryId === null
            ? ['rows' => [], 'unranked' => 0]
            : $this->ranking($categoryId, $period);
        $current = Season::current();

        return Inertia::render('Attendance/Ranking', [
            'categories' => $categories,
            'categoryId' => $categoryId,
            'period' => $period,
            'seasons' => array_map(function (int $back) use ($current): array {
                $season = Season::forStartYear($current->startYear - $back);

                return ['start_year' => $season->startYear, 'label' => $season->label()];
            }, [0, 1, 2]),
            'rows' => array_map(fn (array $row): array => [
                'player_id' => $row['player_id'],
                'rank' => $row['rank'],
                'name' => $row['name'],
                'category' => $row['category'],
                'expected' => $row['expected'],
                'present' => $row['counts']['present'],
                'score_pct' => $row['score_pct'],
            ], $rows),
            'unranked' => $unranked,
            'minExpected' => AttendanceStats::RANKING_MIN_EXPECTED,
        ]);
    }

    /**
     * The ranked period: a month (default: this one) or a season (default:
     * the current one), with its label in the reader's language and a
     * filename key.
     *
     * @return array{type: string, month: string, season: int, from: string, to: string, label: string, key: string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:month,season'],
            'month' => ['nullable', 'date_format:Y-m'],
            'season' => ['nullable', 'integer', 'between:2000,2100'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $seasonYear = isset($data['season']) ? (int) $data['season'] : Season::current()->startYear;

        if (($data['type'] ?? 'month') === 'season') {
            $season = Season::forStartYear($seasonYear);

            return [
                'type' => 'season', 'month' => $month, 'season' => $seasonYear,
                'from' => $season->start()->toDateString(), 'to' => $season->end()->toDateString(),
                'label' => strtr(UiLang::get('att.ranking.season_label'), ['{season}' => $season->label()]),
                'key' => 'season-'.$seasonYear,
            ];
        }

        $anchor = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [
            'type' => 'month', 'month' => $month, 'season' => $seasonYear,
            'from' => $anchor->startOfMonth()->toDateString(), 'to' => $anchor->endOfMonth()->toDateString(),
            'label' => $anchor->locale(app()->getLocale())->translatedFormat('F Y'),
            'key' => $month,
        ];
    }

    /**
     * The category's ranked players, with names, and how many players with
     * marks had too few sessions to be ranked.
     *
     * @return array{rows: list<array<string, mixed>>, unranked: int}
     */
    private function ranking(int $categoryId, array $period): array
    {
        $rows = $this->stats->players($period['from'], $period['to'], $categoryId);
        $ranked = AttendanceStats::ranked($rows);

        return ['rows' => $this->names->attach($ranked), 'unranked' => count($rows) - count($ranked)];
    }
}
