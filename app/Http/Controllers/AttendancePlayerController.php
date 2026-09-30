<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\InjurySpells;
use App\Services\Attendance\PreseasonProgress;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use App\Support\Media;
use App\Support\UiLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One player's attendance, for the profile card. The routes are named
 * attendance.players.*, so the permission middleware gates them on the
 * attendance module: the profile needs players/view, and its attendance
 * card needs attendance/view on top. The card fetches this after the
 * profile has painted, so the profile never waits for it.
 */
class AttendancePlayerController extends Controller
{
    /** The marks a parent letter lists: absences, lates and early departures. */
    public const LETTER_STATUSES = ['late', 'left_early', 'absent_excused', 'absent_unexcused'];

    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PreseasonProgress $preseason,
        private readonly PdfService $pdf,
        private readonly AtRisk $risk,
        private readonly InjurySpells $injuries,
    ) {}

    public function show(Request $request, Player $player): JsonResponse
    {
        $data = $this->data($request, $player);
        ['from' => $from, 'to' => $to] = $data['period'];

        return response()->json([
            ...$data,
            // The card's warning banner, for the card's own period.
            'risk' => $this->risk->forPlayer($player->id, $from, $to, $data['summary']),
            // The card's Injuries section: spells overlapping the period, with details, and unmatched details.
            'injuries' => $this->injuries->forPlayer($player->id, $from, $to),
        ]);
    }

    /** The card as a printable PDF, same period (A4 portrait, right-to-left in Arabic). */
    public function report(Request $request, Player $player): Response
    {
        $player->loadMissing('category');
        $data = $this->data($request, $player);

        $html = view('pdf.attendance-player', [
            ...$data,
            'club' => ClubHeader::data(),
            'player' => $player,
            'photo' => Media::localFile($player->picture_url),
            'labels' => AttendanceSettings::labels(),
            'codes' => AttendanceSettings::codes(),
        ])->render();

        return $this->pdf->stream(
            $html,
            "attendance-{$player->membership_id}-{$data['period']['from']}-{$data['period']['to']}.pdf",
            app()->getLocale() === 'ar',
        );
    }

    /**
     * A formal letter to the player's parents about the period (the card's,
     * or the current season): club header, date, recipient (the first
     * emergency contact, else "parent / guardian of"), the configured subject
     * and text with their placeholders filled in, the absences, lates and
     * early departures, totals, and signature lines. A4 portrait,
     * right-to-left in Arabic.
     */
    public function letter(Request $request, Player $player): Response
    {
        $player->loadMissing('category');
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();
        $summary = $this->stats->players($from, $to, null, $player->id)[$player->id] ?? $this->stats->emptyRow($player->id);
        $rows = array_reverse(array_values(array_filter(
            $this->stats->playerSessions($player->id, $from, $to),
            fn (array $row) => in_array($row['status'], self::LETTER_STATUSES, true),
        )));
        $club = ClubHeader::data();
        $periodText = self::day($from).' – '.self::day($to);
        $counts = $summary['counts'];
        $letter = AttendanceSettings::letter([
            'player' => $player->fullname,
            'category' => $player->category?->localized_name ?? '—',
            'period' => $periodText,
            'absences' => $counts['absent_excused'] + $counts['absent_unexcused'],
            'lates' => $counts['late'],
            'club' => $club['name'] ?? '',
        ]);
        $contact = $player->emergencyContacts()->orderBy('id')->value('name');

        $html = view('pdf.attendance-letter', [
            'club' => $club,
            'date' => now()->format('d/m/Y'),
            'recipient' => $contact ?: strtr(UiLang::get('att.letter.guardian_of'), ['{player}' => $player->fullname]),
            'subject' => $letter['subject'],
            'body' => $letter['body'],
            'periodText' => $periodText,
            'rows' => $rows,
            'summary' => $summary,
            'labels' => AttendanceSettings::labels(),
        ])->render();

        return $this->pdf->stream($html, "attendance-letter-{$player->membership_id}-{$from}-{$to}.pdf", app()->getLocale() === 'ar');
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as printed on letters. */
    private static function day(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, Player $player): array
    {
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
            'period' => $period->toArray(),
            'summary' => $this->stats->players($from, $to, null, $player->id)[$player->id] ?? $this->stats->emptyRow($player->id),
            'monthly' => $this->stats->monthly($from, $to, null, $player->id),
            'preseason' => $player->category_id ? $this->preseason->forCategory((int) $player->category_id, $to) : null,
            'sessions' => $this->stats->playerSessions($player->id, $from, $to),
        ];
    }
}
