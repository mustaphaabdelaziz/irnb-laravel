<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Team;
use App\Services\Competitions\MatchStats;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Export;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/** Club-wide player statistics, filterable by season, team and competition; exports never include ratings. */
class GameStatsController extends Controller
{
    public function __construct(private readonly MatchStats $stats) {}

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $rows = $this->stats->players($f['season'], $f['team_id'], $f['competition_id']);

        return Inertia::render('Games/Stats', [
            'rows' => $rows,
            'leaders' => MatchStats::leaderboards($rows),
            'season' => $f['season'],
            'seasons' => Season::options(),
            'teams' => Team::query()->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name.' · '.$t->category?->localized_name]),
            'competitions' => Competition::query()->where('season_start_year', $f['season'])->orderBy('name')->get(['id', 'name', 'team_id']),
            'filters' => ['team_id' => $f['team_id'], 'competition_id' => $f['competition_id']],
        ]);
    }

    public function export(Request $request, PdfService $pdf): FileResponse
    {
        $f = $this->filters($request);
        $rows = $this->stats->players($f['season'], $f['team_id'], $f['competition_id']);
        $title = UiLang::get('comp.stats.title').' — '.Season::forStartYear($f['season'])->label();
        $filename = 'match-stats-'.$f['season'];
        // Ratings stay in the app: an export gets shared.
        $columns = ['name', 'appearances', 'starts', 'minutes', 'sets', 'goals', 'assists', 'yellows', 'reds', 'motm'];
        $headers = array_map(fn (string $c) => UiLang::get('comp.stats.col_'.$c), $columns);
        $cells = array_map(fn (array $r) => array_map(fn (string $c) => $r[$c], $columns), $rows);

        if ($request->query('format') === 'pdf') {
            $html = view('pdf.match-stats', ['club' => ClubHeader::data(), 'title' => $title, 'headers' => $headers, 'rows' => $cells])->render();

            return $pdf->stream($html, $filename.'.pdf', app()->getLocale() === 'ar', true);
        }

        return Export::download(Export::format($request), $filename, $headers, $cells, $title);
    }

    /** @return array{season: int, team_id: ?int, competition_id: ?int} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'competition_id' => ['nullable', 'integer', 'exists:competitions,id'],
        ]);

        return [
            'season' => (int) ($data['season'] ?? Season::current()->startYear),
            'team_id' => isset($data['team_id']) ? (int) $data['team_id'] : null,
            'competition_id' => isset($data['competition_id']) ? (int) $data['competition_id'] : null,
        ];
    }
}
