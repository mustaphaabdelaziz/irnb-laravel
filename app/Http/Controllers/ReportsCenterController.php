<?php

namespace App\Http\Controllers;

use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportExporter;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * The Reports center: the hub (all categories or one), the generic report
 * viewer and its downloads. The routes are unguarded at route level because
 * each report has its own module; access is checked here, per report, on
 * screen and on download alike.
 */
class ReportsCenterController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function hub(Request $request): Response
    {
        return $this->index($request, null);
    }

    public function category(Request $request, string $category): Response
    {
        return $this->index($request, $category);
    }

    public function show(Request $request, string $category, string $report, ReportRunner $runner): Response
    {
        $report = $this->resolve($request, $category, $report);
        $filters = ReportFilters::fromRequest($request, $report);

        return Inertia::render('Reports/Show', [
            'report' => [
                'id' => $report->id(),
                'category' => $report->category(),
                'key' => $report->key(),
                'title' => $report->title(),
                'description' => $report->description(),
                'icon' => $report->icon(),
            ],
            'filterDefs' => array_map(fn (Filter $f) => $f->toArray(), $report->filters()),
            'filters' => $filters->values(),
            'applied' => $filters->describe(),
            'sortable' => array_keys($report->sortable()),
            'perPageOptions' => ReportFilters::PER_PAGE,
            'columns' => array_map(fn (Column $c) => $c->toArray(), $report->columns()),
            'rows' => $runner->page($report, $filters),
            'totals' => $report->totals($filters) ?: null,
            'summary' => $report->summary($filters),
            'charts' => $report->charts($filters),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);
    }

    public function download(Request $request, string $category, string $report, ReportExporter $exporter): FileResponse
    {
        $report = $this->resolve($request, $category, $report);
        $filters = ReportFilters::fromRequest($request, $report);

        return $exporter->download($report, $filters, (string) $request->query('format', 'xlsx'));
    }

    private function index(Request $request, ?string $category): Response
    {
        $cards = $this->registry->cards($request->user(), $category);
        abort_if($category !== null && $cards === [], 403);

        $counts = array_count_values(array_column($this->registry->cards($request->user()), 'category'));

        return Inertia::render('Reports/Index', [
            'category' => $category,
            'categories' => collect(ReportRegistry::CATEGORIES)
                ->filter(fn (string $c) => ($counts[$c] ?? 0) > 0)
                ->map(fn (string $c) => ['key' => $c, 'icon' => ReportRegistry::ICONS[$c], 'count' => $counts[$c], 'href' => route('reports.category', $c)])
                ->values(),
            'cards' => $cards,
        ]);
    }

    private function resolve(Request $request, string $category, string $key): Report
    {
        $report = $this->registry->find($category, $key);
        abort_if($report === null, 404);
        abort_unless($report->allows($request->user()), 403);

        return $report;
    }
}
