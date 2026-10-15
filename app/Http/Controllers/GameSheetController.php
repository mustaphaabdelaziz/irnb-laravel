<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Competitions\MatchSheets;
use App\Services\Pdf\PdfService;
use Symfony\Component\HttpFoundation\Response;

class GameSheetController extends Controller
{
    public function lineup(Game $game, MatchSheets $sheets, PdfService $pdf): Response
    {
        $html = view('pdf.game-lineup-sheet', $sheets->lineup($game))->render();

        return $pdf->stream($html, "lineup-{$game->id}-{$game->date}.pdf", app()->getLocale() === 'ar');
    }

    public function report(Game $game, MatchSheets $sheets, PdfService $pdf): Response
    {
        $html = view('pdf.game-report-sheet', $sheets->report($game))->render();

        return $pdf->stream($html, "match-report-{$game->id}-{$game->date}.pdf", app()->getLocale() === 'ar');
    }
}
