<?php

namespace App\Http\Controllers;

use App\Http\Requests\LiteraryReportRequest;
use App\Models\LiteraryReport;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class LiteraryReportController extends Controller
{
    public function index(): Response
    {
        $season = Season::current();

        return Inertia::render('LiteraryReports/Index', [
            'reports' => LiteraryReport::query()->latest('period_to')->latest('id')
                ->get(['id', 'title', 'period_from', 'period_to', 'updated_at']),
            // A new report proposes the current season as its period.
            'defaults' => [
                'period_from' => $season->start()->toDateString(),
                'period_to' => $season->end()->toDateString(),
            ],
        ]);
    }

    public function store(LiteraryReportRequest $request): RedirectResponse
    {
        $report = LiteraryReport::create($request->validated() + [
            'sections' => LiteraryReport::SECTIONS,
            'created_by_user_id' => $request->user()->id,
        ]);

        ActivityRecorder::record($request->user(), ActivityAction::LITERARY_REPORT_CREATED, $report);

        return redirect()->route('literary-reports.edit', $report)->with('success', 'flash.literary_report_created');
    }

    public function edit(Request $request, LiteraryReport $literaryReport): Response
    {
        return Inertia::render('LiteraryReports/Edit', [
            'report' => Arr::only($literaryReport->toArray(), [
                'id', 'title', 'period_from', 'period_to', 'sections', 'president_word', 'introduction', 'conclusion',
            ]),
            'sections' => array_map(fn (string $key) => [
                'key' => $key,
                'allowed' => LiteraryReportBuilder::allows($key, $request->user()),
            ], LiteraryReport::SECTIONS),
        ]);
    }

    public function update(LiteraryReportRequest $request, LiteraryReport $literaryReport): RedirectResponse
    {
        $literaryReport->update($request->validated());

        return back()->with('success', 'flash.literary_report_updated');
    }

    public function destroy(LiteraryReport $literaryReport): RedirectResponse
    {
        $literaryReport->delete();

        return redirect()->route('literary-reports.index')->with('success', 'flash.literary_report_deleted');
    }

    public function pdf(LiteraryReport $literaryReport): never
    {
        abort(501); // Task 3
    }
}
