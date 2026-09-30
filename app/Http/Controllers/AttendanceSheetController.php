<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MonthSheet;
use App\Services\Attendance\Roster;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Player\FileNumber;
use App\Support\AttendanceSettings;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
    public const MAX_COLUMNS = 16;

    /** Blank columns after the month's last session, for a session added on the day, when they fit. */
    public const BLANK_COLUMNS = 2;

    /** Blank rows at the end of the session sheet, for a player who came without being on the list. */
    public const BLANK_ROWS = 3;

    public function __construct(private readonly PdfService $pdf) {}

    /** One category's month: players × sessions, A4 landscape (right-to-left in Arabic). */
    public function month(Request $request, MonthSheet $sheet): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
            'filled' => ['nullable', 'boolean'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $locale = app()->getLocale();
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month);

        $columns = $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'day' => CarbonImmutable::createFromFormat('!Y-m-d', $s->date)->locale($locale)->translatedFormat('D'),
            'date' => substr($s->date, 8, 2).'/'.substr($s->date, 5, 2),
            'time' => $s->start_time,
            'kind' => $s->kind->value,
            'title' => $s->title ? Str::limit($s->title, 24) : null,
        ])->values()->all();

        $chunks = array_chunk($columns, self::MAX_COLUMNS);
        $groups = [];
        foreach ($chunks as $i => $chunk) {
            $last = $i === count($chunks) - 1;
            $groups[] = [
                'columns' => $chunk,
                'from' => $i * self::MAX_COLUMNS + 1,
                'to' => $i * self::MAX_COLUMNS + count($chunk),
                'blank' => $last && count($chunk) + self::BLANK_COLUMNS <= self::MAX_COLUMNS ? self::BLANK_COLUMNS : 0,
            ];
        }

        // Players from another category (a joint session's roster, a guest) carry its name.
        $otherIds = $players->pluck('category_id')->filter()->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $category->id)->unique()->values()->all();
        $others = Category::whereIn('id', $otherIds)->get()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);

        $html = view('pdf.attendance-month-sheet', [
            'club' => ClubHeader::data(),
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'monthLabel' => $anchor->locale($locale)->translatedFormat('F Y'),
            'season' => Season::forDate($anchor)->label(),
            'groups' => $groups,
            'total' => count($columns),
            'rows' => $players->map(fn (Player $p) => [
                'id' => $p->id,
                'file_number' => self::fileNumber($p),
                'name' => self::name($p),
                'category' => $others->get((int) $p->category_id),
            ])->values()->all(),
            'cells' => $cells,
            'filled' => $request->boolean('filled'),
            'labels' => AttendanceSettings::labels(),
            'codes' => AttendanceSettings::codes(),
        ])->render();

        return $this->pdf->stream($html, "attendance-sheet-{$category->id}-{$anchor->format('Y-m')}.pdf", $locale === 'ar', true);
    }

    /**
     * One session, A4 portrait: its list (frozen once marked, else the
     * expected roster of every category taking part), a tick column per
     * status, minutes, reason, note, and the session log to fill in.
     */
    public function session(Request $request, TrainingSession $session, Roster $roster): Response
    {
        $request->validate(['filled' => ['nullable', 'boolean']]);
        $filled = $request->boolean('filled');
        $locale = app()->getLocale();
        $session->loadMissing('categories');
        $players = $roster->forSession($session);
        // Primary category first, then the others of a joint pre-season session.
        $categories = $session->orderedCategories()->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $joint = $categories->count() > 1;
        $marks = $filled ? $session->attendances()->get()->keyBy('player_id') : collect();

        $html = view('pdf.attendance-session-sheet', [
            'club' => ClubHeader::data(),
            'session' => [
                'date' => $session->date,
                'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $session->date)->locale($locale)->translatedFormat('l j F Y'),
                'time' => "{$session->start_time}–{$session->end_time}",
                'kind' => $session->kind->value,
                'categories' => $categories->values()->all(),
                'coach' => $session->coach,
                'title' => $session->title,
                'notes' => $session->notes,
                'cancelled' => $session->state === SessionState::Cancelled,
                'cancel_reason' => $session->cancel_reason,
            ],
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint) {
                $mark = $marks->get($p->id);

                return [
                    'file_number' => self::fileNumber($p),
                    'name' => self::name($p),
                    // Where a player comes from only matters when several categories share the session.
                    'category' => $joint ? $categories->get($p->category_id) : null,
                    'status' => $mark?->status->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values()->all(),
            'blankRows' => self::BLANK_ROWS,
            'filled' => $filled,
            'labels' => AttendanceSettings::labels(),
            'codes' => AttendanceSettings::codes(),
            'reasons' => AbsenceReason::values(),
        ])->render();

        return $this->pdf->stream($html, "attendance-session-{$session->id}-{$session->date}.pdf", $locale === 'ar');
    }

    /** The folder number, zero-padded; a dash when the player has none yet. */
    private static function fileNumber(Player $player): string
    {
        return FileNumber::format($player->file_number === null ? null : (int) $player->file_number) ?: '—';
    }

    /** "LASTNAME Firstname", exactly as the month grid lists the player. */
    private static function name(Player $player): string
    {
        return trim("{$player->lastname} {$player->firstname}");
    }
}
