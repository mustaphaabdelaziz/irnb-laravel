<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\PlayerNames;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Export;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Club-wide attendance statistics (`attendance.stats`, view): a period (this
 * month by default, the season, or a custom range), an optional category, and
 * one branch (BranchChoice, shared with the calendar). Every number comes from
 * AttendanceStats.
 */
class AttendanceStatsController extends Controller
{
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PdfService $pdf,
        private readonly PlayerNames $names,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Attendance/Stats', [
            ...$this->data($request),
            'attendanceCodes' => app(AttendanceStatusCatalog::class)->codes(),
        ]);
    }

    /**
     * The player table as XLSX (default) or CSV, or the whole page as a PDF
     * (`format=pdf`), for the same period, branch and category as the page.
     */
    public function export(Request $request): FileResponse
    {
        $data = $this->data($request);
        ['from' => $from, 'to' => $to] = $data['period'];
        $categoryName = $data['categoryId'] !== null
            ? collect($data['categories'])->firstWhere('id', $data['categoryId'])['name']
            : UiLang::get('att.all_categories');
        $branchName = $data['branchId'] !== null ? collect($data['branches'])->firstWhere('id', $data['branchId'])['name'] : null;
        $filename = 'attendance-stats-'.$from.'-'.$to
            .($data['branchId'] !== null ? '-b'.$data['branchId'] : '')
            .($data['categoryId'] !== null ? '-'.$data['categoryId'] : '');
        $catalog = app(AttendanceStatusCatalog::class);
        // A hidden custom code only gets a column while the data has marks for it.
        $labels = $catalog->labels(null, $catalog->withMarks($data['totals']['counts'], ...array_column($data['categoryRows'], 'counts')));

        if ($request->query('format') === 'pdf') {
            $html = view('pdf.attendance-stats', [
                ...$data,
                'club' => ClubHeader::data(),
                'categoryName' => $categoryName,
                'branchName' => $branchName,
                'labels' => $labels,
                'codes' => $catalog->codes(),
            ])->render();

            return $this->pdf->stream($html, $filename.'.pdf', app()->getLocale() === 'ar', true);
        }

        $headers = [UiLang::get('col.member'), UiLang::get('col.membership_id'), UiLang::get('col.category'), UiLang::get('att.col.expected')];
        foreach ($labels as $name) {
            array_push($headers, $name, $name.' %');
        }
        array_push($headers, UiLang::get('att.col.late_minutes'), UiLang::get('att.col.missed_hours'), UiLang::get('att.col.score'), UiLang::get('att.col.score_pct'));

        $rows = array_map(function (array $row) use ($labels): array {
            $cells = [$row['name'], (string) $row['membership_id'], $row['category'] ?? '', $row['expected']];
            foreach (array_keys($labels) as $status) {
                array_push($cells, $row['counts'][$status], $row['pct'][$status]);
            }

            return [...$cells, $row['late_minutes'], $row['missed_hours'], $row['score'], $row['score_pct']];
        }, $data['players']);

        return Export::download(Export::format($request), $filename, $headers, $rows,
            implode(' — ', array_filter([UiLang::get('att.stats_title'), $data['period']['label'], $branchName, $categoryName])));
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $branchId = BranchChoice::fromRequest($request);
        $period = ActivityPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        $rows = $this->stats->players($from, $to, $categoryId, null, $branchId);
        $players = $this->withNames($rows);
        $sessions = $this->stats->sessionsByMonth($from, $to, $categoryId, $branchId);

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
            'totals' => [
                ...$this->stats->summarize($rows),
                'held' => array_sum($sessions['held']),
                'cancelled' => array_sum($sessions['cancelled']),
            ],
            'monthly' => $this->stats->monthly($from, $to, $categoryId, null, $branchId),
            'sessionsByMonth' => $sessions,
            'categoryRows' => $this->stats->categories($from, $to, $branchId),
            'players' => $players,
            // The table stays historical (departed/archived players included); the owner
            // decided they are not ranked, so only active players feed the top/bottom 5.
            'ranking' => AttendanceStats::ranking(array_values(array_filter($players, fn (array $row) => $row['active']))),
        ];
    }

    /** Adds each player's name, membership id and current category (PlayerNames); sorted by name. */
    private function withNames(array $rows): array
    {
        return collect($this->names->attach($rows))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
