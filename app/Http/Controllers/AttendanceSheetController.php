<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\MonthSheet;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SheetColumns;
use App\Services\Attendance\SheetTotals;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Season;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paper sheets the coach takes to the pitch and fills in by hand; the staff
 * type them into the month grid afterwards. Blank by default; `filled=1`
 * prints what is already recorded, as a record. Both routes only read
 * (attendance/view, see config/permissions.php).
 */
class AttendanceSheetController extends Controller
{
    /** Session columns per printed table; more continue in a second part on a new page. */
    public const MAX_COLUMNS = SheetColumns::MAX;

    /** Blank columns after the month's last session, for a session added on the day, when they fit. */
    public const BLANK_COLUMNS = SheetColumns::BLANK;

    /** Blank rows at the end of the session sheet, for a player who came without being on the list. */
    public const BLANK_ROWS = 3;

    public function __construct(private readonly PdfService $pdf) {}

    /** One category's month in one branch (BranchChoice, as on the calendar): players × sessions, A4 landscape (right-to-left in Arabic). */
    public function month(Request $request, MonthSheet $sheet, AttendanceStatusCatalog $catalog, SheetTotals $totals): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
            'filled' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // As on the month grid: a training group, or 0 = the sessions with no group; none = the first group.
            'group_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $locale = app()->getLocale();
        $switch = $sheet->groupSwitch($category->id, $anchor->year, $anchor->month, $branch?->id, isset($data['group_id']) ? (int) $data['group_id'] : null);
        ['sessions' => $sessions, 'cells' => $cells, 'statuses' => $statuses, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month, $branch?->id, $switch['selected']);
        // What the header prints: the group's name, «whole category» for the sessions with no group,
        // nothing for a category without groups.
        $shown = collect($switch['options'])->firstWhere('id', $switch['selected']);
        $trainingGroup = $shown === null ? null : ($shown['name'] ?? UiLang::get('att.group_whole'));

        $columns = SheetColumns::of($sessions, $locale);
        $groups = SheetColumns::parts($columns);

        // Present · Absent · Rate per player, on the filled sheet only (a blank sheet has nothing to count).
        $rowTotals = null;
        if ($request->boolean('filled')) {
            $rowTotals = [];
            foreach ($players as $player) {
                $rowTotals[$player->id] = $totals->of($statuses[$player->id] ?? []);
            }
        }

        // Players from another category carry its name (their CURRENT category, see guestCategories()
        // and rows_follow_the_roster_rules()).
        $guests = $sheet->guestCategories($players, $category->id, $branch?->id);

        $html = view('pdf.attendance-month-sheet', [
            'club' => ClubHeader::data(),
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'branch' => $branch?->localized_name,
            'trainingGroup' => $trainingGroup,
            'monthLabel' => $anchor->locale($locale)->translatedFormat('F Y'),
            'season' => Season::forDate($anchor)->label(),
            'groups' => $groups,
            'total' => count($columns),
            'rows' => $players->map(fn (Player $p) => [
                'id' => $p->id,
                'name' => self::name($p),
                'category' => $guests[$p->id] ?? null,
            ])->values()->all(),
            'cells' => $cells,
            'totals' => $rowTotals,
            'filled' => $request->boolean('filled'),
            'labels' => $catalog->labels(null, $catalog->shown($sessions->flatMap->attendances->pluck('status'))),
            'codes' => $catalog->codes(),
        ])->render();

        // One file name per group: a downloaded sheet of another group is never mistaken for this one.
        $suffix = $switch['selected'] === null ? '' : "-g{$switch['selected']}";

        return $this->pdf->stream($html, "attendance-sheet-{$category->id}-{$anchor->format('Y-m')}{$suffix}.pdf", $locale === 'ar', true);
    }

    /**
     * One session, A4 portrait: its list (frozen once marked, else the
     * expected roster of every category taking part), a tick column per
     * status, minutes, reason, note, and the session log to fill in.
     */
    public function session(Request $request, TrainingSession $session, Roster $roster, AttendanceStatusCatalog $catalog): Response
    {
        $request->validate(['filled' => ['nullable', 'boolean']]);
        $filled = $request->boolean('filled');
        $locale = app()->getLocale();
        $session->loadMissing('categories', 'branch', 'group');
        $players = $roster->forSession($session);
        // Primary category first, then the others of a joint pre-season session.
        $categories = $session->orderedCategories()->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $joint = $categories->count() > 1;
        $rowCategories = $joint ? $roster->categoryIdsIn($players, $session->branch_id) : [];
        // Loaded once, always: the category tag needs the mark's frozen category_id
        // even on a blank sheet, only the status/minutes/reason/note stay gated by $filled.
        $marks = $session->attendances()->get()->keyBy('player_id');

        $html = view('pdf.attendance-session-sheet', [
            'club' => ClubHeader::data(),
            'session' => [
                'date' => $session->date,
                'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $session->date)->locale($locale)->translatedFormat('l j F Y'),
                'time' => "{$session->start_time}–{$session->end_time}",
                'kind' => $session->kind->value,
                // The sport, when the club has branches (null = a club-wide session).
                'branch' => $session->branch?->localized_name,
                'categories' => $categories->values()->all(),
                // The training group whose members make the list; null = the whole category.
                'group' => $session->group?->name,
                'coach' => $session->coach,
                'title' => $session->title,
                'notes' => $session->notes,
                'cancelled' => $session->state === SessionState::Cancelled,
                'cancel_reason' => $session->cancel_reason,
            ],
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint, $filled, $rowCategories) {
                $mark = $marks->get($p->id);

                return [
                    'name' => self::name($p),
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one in this branch, so a later change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $rowCategories[$p->id] ?? null) : null,
                    'status' => $filled ? $mark?->status : null,
                    'minutes' => $filled ? $mark?->minutes : null,
                    'reason' => $filled ? $mark?->reason?->value : null,
                    'note' => $filled ? $mark?->note : null,
                ];
            })->values()->all(),
            'blankRows' => self::BLANK_ROWS,
            'filled' => $filled,
            'labels' => $catalog->labels(null, $catalog->shown($filled ? $marks->pluck('status') : [])),
            'codes' => $catalog->codes(),
            'reasons' => AbsenceReason::values(),
        ])->render();

        return $this->pdf->stream($html, "attendance-session-{$session->id}-{$session->date}.pdf", $locale === 'ar');
    }

    /** Player::fullname, exactly as the month grid and the session page list the player. */
    private static function name(Player $player): string
    {
        return $player->fullname;
    }
}
