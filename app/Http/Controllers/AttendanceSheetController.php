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
            'title' => $s->title ? Str::limit(self::wrapLongWords($s->title), 24) : null,
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
        // Deliberately the player's CURRENT category_id, not any mark's frozen one: unlike the
        // session sheet/screen, the month grid is a forward-looking blank sheet the coach fills
        // in on the pitch, so it lists people as they are today (see rows_follow_the_roster_rules()).
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
                'categories' => $categories->values()->all(),
                'coach' => $session->coach,
                'title' => $session->title,
                'notes' => $session->notes,
                'cancelled' => $session->state === SessionState::Cancelled,
                'cancel_reason' => $session->cancel_reason,
            ],
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint, $filled) {
                $mark = $marks->get($p->id);

                return [
                    'file_number' => self::fileNumber($p),
                    'name' => self::name($p),
                    // Where a player comes from only matters when several categories share the session.
                    // Once marked, the mark's own category_id (fixed at marking time) wins over the
                    // player's current one, so a later category change never retags a frozen session.
                    'category' => $joint ? $categories->get($mark?->category_id ?? $p->category_id) : null,
                    'status' => $filled ? $mark?->status->value : null,
                    'minutes' => $filled ? $mark?->minutes : null,
                    'reason' => $filled ? $mark?->reason?->value : null,
                    'note' => $filled ? $mark?->note : null,
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

    /**
     * Breaks any word over $width characters into $width-character chunks
     * joined by spaces, so mPDF can wrap a long session title instead of
     * widening the whole month column to fit it. wordwrap() itself is
     * byte-based and would cut a multibyte word (e.g. Arabic) mid-character;
     * mb_str_split() counts characters, not bytes, so it never does.
     */
    private static function wrapLongWords(string $text, int $width = 10): string
    {
        return implode(' ', array_map(
            fn (string $word) => mb_strlen($word) > $width ? implode(' ', mb_str_split($word, $width)) : $word,
            explode(' ', $text)
        ));
    }
}
