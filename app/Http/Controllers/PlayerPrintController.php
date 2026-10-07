<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Player;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Player\AcademicResultsSheet;
use App\Support\Export;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the office prints: the board table of a category's members, and the
 * academic results sheet.
 */
class PlayerPrintController extends Controller
{
    public function __construct(private PdfService $pdf) {}

    /**
     * Everyone in one category this season — reprinted when players change
     * category.
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
            $rows = $players->values()->map(fn (Player $player, int $i) => [
                $i + 1,
                $player->fullname,
                (string) $player->membership_id,
            ]);
            $headers = ['#', ...array_map(fn (string $key) => UiLang::get($key), [
                'col.member', 'col.membership_id',
            ])];

            return Export::download($format, 'board-table-'.$category->id.'-'.$season->startYear, $headers, $rows->all(),
                ($category->localized_name ?: $category->name).' — '.$season->label());
        }

        $html = view('pdf.board-table', [
            'club' => ClubHeader::data(),
            'category' => $category,
            'season' => $season,
            'players' => $players,
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
}
