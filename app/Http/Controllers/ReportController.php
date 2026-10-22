<?php

namespace App\Http\Controllers;

use App\Enums\EducationLevel;
use App\Models\BoardMeeting;
use App\Models\FinanceCategory;
use App\Models\InventorySession;
use App\Models\Player;
use App\Models\Transaction;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\FiscalPeriod;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private PdfService $pdf) {}

    public function playerCard(Player $player): Response
    {
        $player->load(['category', 'position']);

        $html = view('pdf.member-card', [
            'club' => ClubHeader::data(),
            'player' => $player,
            'photo' => $this->resolveMediaFile($player->picture_url),
        ])->render();

        return $this->pdf->stream($html, "member-card-{$player->membership_id}.pdf");
    }

    public function academicReport(Player $player): Response
    {
        abort_unless($player->is_student, 404);

        $player->load(['category', 'academicYears.records']);

        // Pass mark per scale in use (primary /10, everything else /20), for the legend at the bottom.
        $scalePassMarks = collect(EducationLevel::cases())
            ->mapWithKeys(fn (EducationLevel $level) => [$level->scale() => $level->passMark()])
            ->sortKeys();

        $html = view('pdf.academic-report', [
            'club' => ClubHeader::data(),
            'player' => $player,
            'photo' => $this->resolveMediaFile($player->picture_url),
            'academicYears' => $player->academicYears,
            'scalePassMarks' => $scalePassMarks,
        ])->render();

        return $this->pdf->stream($html, "academic-report-{$player->membership_id}.pdf");
    }

    public function financialSummary(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: FiscalPeriod::currentKey());

        $base = Transaction::query()->where('archived', false)->where('fiscal_year', $year);

        $totalIncome = (float) (clone $base)->where('transaction_type', 'income')->sum('amount');
        $totalExpense = (float) (clone $base)->where('transaction_type', 'expense')->sum('amount');
        $totalDonations = (float) (clone $base)->where('category', 'donation')->sum('amount');

        $names = FinanceCategory::query()->get()->keyBy('id');
        $byCategory = DB::table('transactions')
            ->where('archived', false)
            ->where('fiscal_year', $year)
            ->groupBy('transaction_type', 'finance_category_id')
            ->selectRaw('transaction_type, finance_category_id, SUM(amount) as total')
            ->orderBy('transaction_type')
            ->get()
            ->each(function ($row) use ($names) {
                $row->category = $names->get($row->finance_category_id)?->localized_name ?? __('Other');
            });

        $html = view('pdf.financial-summary', [
            'club' => ClubHeader::data(),
            'year' => $year,
            'season' => FiscalPeriod::label($year),
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
            'totalDonations' => $totalDonations,
            'netBalance' => $totalIncome - $totalExpense,
            'byCategory' => $byCategory,
        ])->render();

        return $this->pdf->stream($html, "financial-summary-{$year}.pdf");
    }

    public function boardMinutes(BoardMeeting $meeting): Response
    {
        $meeting->load(['attendances.member', 'tasks.member', 'createdBy:id,name']);

        $html = view('pdf.meeting-minutes', [
            'club' => ClubHeader::data(),
            'meeting' => $meeting,
        ])->render();

        return $this->pdf->stream($html, "minutes-{$meeting->id}.pdf");
    }

    public function inventoryReport(InventorySession $session): Response
    {
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);

        $html = view('pdf.inventory-report', [
            'club' => ClubHeader::data(),
            'session' => $session,
        ])->render();

        return $this->pdf->stream($html, "inventory-{$session->reference}.pdf");
    }

    /**
     * mPDF needs a filesystem path for images, not a URL. Our stored media URLs
     * are host-relative (/media/... or /storage/...) or legacy absolute URLs;
     * resolve any of them to the public-disk file, which works in both the web
     * app and the packaged desktop app. Returns null if the file is missing.
     *
     * Delegates to Media::localFile(), shared with PlayerPrintController, which
     * also guards against `..`/absolute-path traversal and double-checks
     * containment via realpath().
     */
    private function resolveMediaFile(?string $url): ?string
    {
        return Media::localFile($url);
    }
}
