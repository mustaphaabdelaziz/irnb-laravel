<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\PlayerNames;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Season;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * One category's attendance ranking in one branch (BranchChoice, shared with
 * the calendar) for a month or a season, and (Task 9)
 * the certificates printed from it. Players are ranked by
 * AttendanceStats::ranked() — the statistics page's rules — over the marks
 * attributed to the category, as the statistics page does when filtered on
 * it. Only reads (attendance/view, see config/permissions.php).
 */
class AttendanceRankingController extends Controller
{
    /** Certificates "Print top 3" prints; only these places are printed on a certificate. */
    public const PODIUM = 3;

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
        $branchId = BranchChoice::fromRequest($request);
        $period = $this->period($request);
        ['rows' => $rows, 'unranked' => $unranked] = $categoryId === null
            ? ['rows' => [], 'unranked' => 0]
            : $this->ranking($categoryId, $period, $branchId);
        $current = Season::current();

        return Inertia::render('Attendance/Ranking', [
            'categories' => $categories,
            'categoryId' => $categoryId,
            'branches' => BranchChoice::options(),
            'branchId' => $branchId,
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
                'present' => $row['scored']['present'],
                'score_pct' => $row['score_pct'],
            ], $rows),
            'unranked' => $unranked,
            'minExpected' => AttendanceStats::RANKING_MIN_EXPECTED,
        ]);
    }

    /**
     * Certificates of assiduity, one A4 landscape page each: the podium of
     * the category's ranking for the period, or one chosen player (with a
     * place only when on the podium). No podium without a ranked player.
     */
    public function certificates(Request $request): FileResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'player_id' => ['nullable', 'integer', 'exists:players,id'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branchId = BranchChoice::fromRequest($request);
        $branch = $branchId === null ? null : Branch::find($branchId);
        $period = $this->period($request);
        ['rows' => $rows] = $this->ranking($category->id, $period, $branchId);
        $branchKey = $branchId === null ? '' : "-b{$branchId}";

        if (isset($data['player_id'])) {
            $playerId = (int) $data['player_id'];
            $row = collect($rows)->firstWhere('player_id', $playerId);
            if ($row === null) {
                $own = $this->stats->players($period['from'], $period['to'], $category->id, $playerId, $branchId)[$playerId] ?? null;
                // Neither expected in the branch's category over the period nor in it now: no certificate "for" it.
                $inItNow = (int) Player::whereKey($playerId)->value('category_id') === $category->id
                    && ($branchId === null || DB::table('branch_player')->where('branch_id', $branchId)->where('player_id', $playerId)->exists());
                abort_if(($own['expected'] ?? 0) === 0 && ! $inItNow, 404);
                $row = $this->names->attach([$own ?? $this->stats->emptyRow($playerId)])[0];
                // Departed or archived: no certificate for them either, even outside the ranking.
                abort_if(! $row['active'], 404);
            }
            $chosen = [$row];
            $filename = "certificate-{$row['membership_id']}{$branchKey}-{$period['key']}.pdf";
        } else {
            $chosen = array_slice($rows, 0, self::PODIUM);
            abort_if($chosen === [], 404);
            $filename = "certificates-{$category->id}{$branchKey}-{$period['key']}.pdf";
        }

        $html = view('pdf.attendance-certificates', [
            'club' => ClubHeader::data(),
            // The certificate line's {category}: "Branch · Category" once the club has branches.
            'category' => $branch === null ? $category->localized_name : $branch->localized_name.' · '.$category->localized_name,
            'periodLabel' => $period['label'],
            'date' => now()->format('d/m/Y'),
            'certificates' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'rank' => isset($row['rank']) && $row['rank'] <= self::PODIUM ? $row['rank'] : null,
                'score_pct' => $row['score_pct'],
            ], $chosen),
        ])->render();

        return $this->pdf->stream($html, $filename, app()->getLocale() === 'ar', true);
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
     * The category's ranked players in the branch, with names, and how many active
     * players with marks had too few sessions to be ranked. A departed or
     * archived player (PlayerNames' `active` flag) is dropped before
     * ranking: no rank, no medal, and not counted among the unranked
     * either — they simply are not in the pool being ranked.
     *
     * @return array{rows: list<array<string, mixed>>, unranked: int}
     */
    private function ranking(int $categoryId, array $period, ?int $branchId = null): array
    {
        $rows = $this->stats->players($period['from'], $period['to'], $categoryId, null, $branchId);
        $active = array_values(array_filter($this->names->attach($rows), fn (array $row) => $row['active']));
        $ranked = AttendanceStats::ranked($active);

        return ['rows' => $ranked, 'unranked' => count($active) - count($ranked)];
    }
}
