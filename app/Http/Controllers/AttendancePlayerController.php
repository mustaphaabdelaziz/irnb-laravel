<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\PreseasonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One player's attendance, for the profile card. The routes are named
 * attendance.players.*, so the permission middleware gates them on the
 * attendance module: the profile needs players/view, and its attendance
 * card needs attendance/view on top. The card fetches this after the
 * profile has painted, so the profile never waits for it.
 */
class AttendancePlayerController extends Controller
{
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PreseasonProgress $preseason,
    ) {}

    public function show(Request $request, Player $player): JsonResponse
    {
        return response()->json($this->data($request, $player));
    }

    /** @return array<string, mixed> */
    private function data(Request $request, Player $player): array
    {
        // The current season unless the request picks a period.
        $period = $request->query('period') === null ? ActivityPeriod::season() : ActivityPeriod::fromRequest($request);
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
