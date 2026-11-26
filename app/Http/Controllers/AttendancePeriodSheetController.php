<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\PeriodSheet;
use App\Services\Attendance\SheetColumns;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Season;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The recorded attendance of a period (`attendance.sheets.period`, view):
 * per category and training group, one page per month with each player's
 * codes and Present · Absent · Rate, then a period summary. Launched from
 * the statistics page and the month grid.
 */
class AttendancePeriodSheetController extends Controller
{
    public function __construct(private readonly PdfService $pdf) {}

    public function __invoke(Request $request, PeriodSheet $sheet, AttendanceStatusCatalog $catalog): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            // As on the month sheet: a training group, or 0 = the sessions with no group; none = every group.
            'group_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to']);
        if ($to->greaterThan($from->addMonthsNoOverflow(PeriodSheet::MAX_MONTHS)->subDay())) {
            throw ValidationException::withMessages(['to' => UiLang::get('att.sheet.period_too_long')]);
        }

        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : null;
        $locale = app()->getLocale();
        $sections = $sheet->build($data['from'], $data['to'], $branch?->id, $categoryId, $groupId);

        $used = collect($sections)->flatMap(fn (array $s) => collect($s['months'])
            ->flatMap(fn (array $m) => $m['sessions']->flatMap->attendances->pluck('status')));
        $seasonFrom = Season::forDate($from)->label();
        $seasonTo = Season::forDate($to)->label();

        $html = view('pdf.attendance-period-sheet', [
            'club' => ClubHeader::data(),
            'branch' => $branch?->localized_name,
            'periodLabel' => $from->format('d/m/Y').' → '.$to->format('d/m/Y'),
            'season' => $seasonFrom === $seasonTo ? $seasonFrom : $seasonFrom.' – '.$seasonTo,
            'sections' => array_map(fn (array $s) => [
                'category' => $s['category']->localized_name,
                'group' => $s['group'],
                'months' => array_map(fn (array $m) => [
                    'label' => CarbonImmutable::createFromFormat('!Y-m', $m['month'])->locale($locale)->translatedFormat('F Y'),
                    'parts' => SheetColumns::parts(SheetColumns::of($m['sessions'], $locale), false),
                    'rows' => $m['players']->map(fn (Player $p) => [
                        'id' => $p->id, 'name' => $p->fullname, 'category' => $m['guests'][$p->id] ?? null,
                    ])->values()->all(),
                    'cells' => $m['cells'],
                    'totals' => $m['totals'],
                ], $s['months']),
                'summary' => $s['summary'] === null ? null : array_map(fn (array $r) => [
                    'name' => $r['player']->fullname, 'category' => $r['guest'], 'totals' => $r['totals'],
                ], $s['summary']),
            ], $sections),
            'labels' => $catalog->labels(null, $catalog->shown($used)),
            'codes' => $catalog->codes(),
        ])->render();

        $filename = "attendance-period-{$data['from']}-{$data['to']}"
            .($branch !== null ? '-b'.$branch->id : '')
            .($categoryId !== null ? '-'.$categoryId : '')
            .($categoryId !== null && $groupId !== null ? '-g'.$groupId : '');

        return $this->pdf->stream($html, $filename.'.pdf', $locale === 'ar', true);
    }
}
