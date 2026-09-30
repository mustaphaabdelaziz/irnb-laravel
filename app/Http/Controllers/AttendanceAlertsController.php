<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Support\Export;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Players at risk (`attendance.alerts`, view): a period, the current season
 * by default, and an optional category (the players' current one). The
 * rules and every number come from AtRisk / AttendanceStats.
 */
class AttendanceAlertsController extends Controller
{
    public function __construct(private readonly AtRisk $risk) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Attendance/Alerts', $this->data($request));
    }

    /** The list as XLSX (default) or CSV, for the same period and category as the page. */
    public function export(Request $request): FileResponse
    {
        $data = $this->data($request);
        ['from' => $from, 'to' => $to] = $data['period'];
        $categoryName = $data['categoryId'] !== null
            ? collect($data['categories'])->firstWhere('id', $data['categoryId'])['name']
            : UiLang::get('att.all_categories');

        $headers = [
            UiLang::get('col.member'), UiLang::get('col.membership_id'), UiLang::get('att.category'),
            UiLang::get('att.col.expected'), UiLang::get('att.col.score_pct'), UiLang::get('att.risk.col.unexcused'),
            UiLang::get('att.risk.col.current_streak'), UiLang::get('att.risk.col.longest_streak'),
            UiLang::get('att.risk.col.last_session'), UiLang::get('att.risk.col.reasons'),
        ];
        $rows = array_map(fn (array $row): array => [
            $row['name'], (string) $row['membership_id'], $row['category'] ?? '', $row['expected'], $row['score_pct'],
            $row['unexcused'], $row['current_streak'], $row['longest_streak'], $row['last_date'] ?? '',
            implode(' · ', array_filter([
                $row['low_score'] ? UiLang::get('att.risk.flag.low_score') : null,
                $row['streak'] ? UiLang::get('att.risk.flag.streak') : null,
            ])),
        ], $data['rows']);

        return Export::download(
            Export::format($request),
            'attendance-at-risk-'.$from.'-'.$to.($data['categoryId'] !== null ? '-'.$data['categoryId'] : ''),
            $headers,
            $rows,
            UiLang::get('att.risk.title').' — '.$data['period']['label'].' — '.$categoryName,
        );
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'thresholds' => $this->risk->thresholds(),
            'rows' => $this->risk->list($from, $to, $categoryId),
        ];
    }
}
