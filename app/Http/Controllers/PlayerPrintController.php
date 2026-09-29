<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Player;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Player\AcademicResultsSheet;
use App\Services\Player\FileNumber;
use App\Support\Export;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the office prints: the label stuck on a paper folder, and the board
 * table pinned next to the cabinet.
 */
class PlayerPrintController extends Controller
{
    /**
     * Capped so a crafted link cannot force a giant, slow-to-render sheet —
     * same limit `BulkUpdatePlayersRequest` and the equipment bulk actions
     * already use for a selection of ids.
     */
    private const MAX_IDS = 500;

    public function __construct(private PdfService $pdf) {}

    public function label(Player $player): Response
    {
        return $this->renderLabels(collect([$player->load('category')]), "folder-label-{$player->membership_id}.pdf");
    }

    public function labels(Request $request): Response
    {
        $validated = $request->validate([
            // The string-length cap is a cheap first line of defence against a
            // pathologically long query string; the real cap is the count
            // check below, which runs after junk is filtered out.
            'ids' => ['required', 'string', 'max:8000'],
        ]);

        $ids = collect(explode(',', $validated['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->count() > self::MAX_IDS) {
            return back()->withErrors(['ids' => __('Select :count players or fewer at a time.', ['count' => self::MAX_IDS])]);
        }

        $players = Player::query()->with('category')->whereIn('id', $ids)->orderBy('file_number')->get();

        if ($players->isEmpty()) {
            return back()->withErrors(['ids' => __('No player matched that selection.')]);
        }

        return $this->renderLabels($players, 'folder-labels.pdf');
    }

    /**
     * The sheet pinned next to the cabinet: everyone in one category this
     * season, with the folder number to pull. Folders never move — only this
     * list is reprinted when players change category.
     */
    public function boardTable(Request $request): Response
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $category = Category::findOrFail($validated['category_id']);
        $season = isset($validated['season'])
            ? Season::forStartYear((int) $validated['season'])
            : Season::current();

        $players = Player::query()
            ->where('category_id', $category->id)
            ->where('archived', false)
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();

        // PDF stays the default (it is the printed sheet); xlsx/csv are extras.
        $format = $request->query('format');
        if (in_array($format, [Export::XLSX, Export::CSV], true)) {
            $drawerSize = FileNumber::drawerSize();
            $rows = $players->values()->map(fn (Player $player, int $i) => [
                $i + 1,
                $player->fullname,
                (string) $player->membership_id,
                FileNumber::format($player->file_number) ?: '—',
                $player->file_number ? FileNumber::drawer($player->file_number, $drawerSize) : '—',
            ]);
            $headers = ['#', ...array_map(fn (string $key) => UiLang::get($key), [
                'col.member', 'col.membership_id', 'col.file_number', 'col.drawer',
            ])];

            return Export::download($format, 'board-table-'.$category->id.'-'.$season->startYear, $headers, $rows->all(),
                ($category->localized_name ?: $category->name).' — '.$season->label());
        }

        $html = view('pdf.board-table', [
            'club' => ClubHeader::data(),
            'category' => $category,
            'season' => $season,
            'players' => $players,
            // Computed once here rather than per row in the view: FileNumber::drawer()
            // queries WebsiteConfig::singleton() when not given a size, which would
            // otherwise turn the table into one query per player.
            'drawerSize' => FileNumber::drawerSize(),
        ])->render();

        return $this->pdf->stream($html, 'board-table-'.$category->id.'-'.$season->startYear.'.pdf');
    }

    /**
     * One school year's grades for every active student, one section per
     * category (or just the chosen one), best year average first.
     */
    public function academicResults(Request $request): Response
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'school_year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
        ]);

        $schoolYear = (int) ($validated['school_year'] ?? Season::current()->startYear);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;

        $html = view('pdf.academic-results', [
            'club' => ClubHeader::data(),
            'schoolYear' => $schoolYear,
            'sections' => AcademicResultsSheet::build($schoolYear, $categoryId),
        ])->render();

        return $this->pdf->stream($html, 'academic-results-'.$schoolYear.'-'.($categoryId ?? 'all').'.pdf');
    }

    private function renderLabels(Collection $players, string $filename): Response
    {
        $html = view('pdf.folder-label', [
            'club' => ClubHeader::data(),
            'players' => $players,
            // Same reasoning as boardTable() above: compute once, not per label.
            'drawerSize' => FileNumber::drawerSize(),
        ])->render();

        return $this->pdf->stream($html, $filename);
    }
}
