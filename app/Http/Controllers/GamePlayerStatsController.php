<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Competitions\MatchStats;
use App\Services\Competitions\SuspensionStatus;
use App\Support\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The player page's Matches section (loaded on its own): season totals, matches and active suspensions. */
class GamePlayerStatsController extends Controller
{
    public function show(Request $request, Player $player, MatchStats $stats, SuspensionStatus $status): JsonResponse
    {
        $season = (int) ($request->validate(['season' => ['nullable', 'integer', 'min:2000', 'max:2100']])['season'] ?? Season::current()->startYear);
        $suspensions = $player->suspensions()->with('competition')->get();
        $eval = $status->evaluate($suspensions);

        return response()->json([
            'season' => $season,
            'seasons' => Season::options(),
            'totals' => $stats->players($season, null, null, $player->id),
            'games' => $stats->games($player->id, $season),
            'suspensions' => $suspensions->filter(fn ($s) => $eval[$s->id]['active'])->map(fn ($s) => [
                'competition' => $s->competition?->name,
                'cause' => $s->cause,
                'reason' => $s->reason,
                'kind' => $s->kind,
                'remaining' => $eval[$s->id]['remaining'],
                'until' => $eval[$s->id]['until'],
            ])->values(),
        ]);
    }
}
