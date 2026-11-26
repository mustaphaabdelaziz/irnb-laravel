<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerDuplicateDecision;
use App\Services\Player\Duplicates\DuplicateFinder;
use App\Services\Player\Duplicates\DuplicateScorer;
use App\Services\Player\Duplicates\PlayerCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «Doublons potentiels»: scored pairs, "not duplicate" decisions and their undo. */
class PlayerDuplicateController extends Controller
{
    private const PER_PAGE = 50;

    private const MIN_SCORES = [0, 50, 100, 150, 200];

    private const DEFAULT_MIN_SCORE = 100;

    public function index(Request $request, DuplicateFinder $finder): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'dismissed', 'merged'])],
            'min_score' => ['nullable', 'integer', Rule::in(self::MIN_SCORES)],
            'criteria' => ['nullable', 'array'],
            'criteria.*' => [Rule::in(array_keys(DuplicateScorer::CRITERIA))],
        ]);
        $status = $filters['status'] ?? 'pending';
        // Owner 2026-11-26: the list opens on strong matches; 0 ("All") stays selectable.
        $minScore = (int) ($filters['min_score'] ?? self::DEFAULT_MIN_SCORE);
        $criteria = array_values($filters['criteria'] ?? []);

        $rows = $status === 'pending'
            ? $finder->pending($criteria, $minScore)->map(fn (array $pair) => [
                ...$pair, 'status' => 'pending', 'decision_id' => null, 'decided_at' => null,
                'kept_player_id' => null, 'snapshot_a' => null, 'snapshot_b' => null,
            ])
            : $this->decided($status, $criteria, $minScore);

        $page = max(1, (int) $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
        $cards = PlayerCards::for($paginator->getCollection()
            ->flatMap(fn (array $row) => [$row['a'], $row['b']])->filter()->unique()->values()->all());

        return Inertia::render('Players/Duplicates', [
            'filters' => ['status' => $status, 'min_score' => $minScore, 'criteria' => $criteria],
            'total' => $rows->count(),
            'criteriaOptions' => array_keys(DuplicateScorer::CRITERIA),
            'minScores' => self::MIN_SCORES,
            'pairs' => $paginator->through(fn (array $row) => [
                'key' => ($row['decision_id'] ?? 'p').'-'.$row['a'].'-'.$row['b'],
                // Merged rows show what the players were; the others show them as they are now.
                'a' => $row['status'] === 'merged' ? $row['snapshot_a'] : ($cards[$row['a']] ?? $row['snapshot_a']),
                'b' => $row['status'] === 'merged' ? $row['snapshot_b'] : ($cards[$row['b']] ?? $row['snapshot_b']),
                'score' => $row['score'],
                'reasons' => $row['reasons'],
                'status' => $row['status'],
                'decision_id' => $row['decision_id'],
                'decided_at' => $row['decided_at'],
                'kept_player_id' => $row['kept_player_id'],
            ]),
        ]);
    }

    public function dismiss(Request $request, DuplicateFinder $finder): RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer', 'exists:players,id'],
            'b' => ['required', 'integer', 'exists:players,id', 'different:a'],
        ]);
        [$a, $b] = PlayerDuplicateDecision::orderedPair((int) $data['a'], (int) $data['b']);
        $players = Player::whereIn('id', [$a, $b])->get()->keyBy('id');
        $result = $finder->scorePair($a, $b);

        PlayerDuplicateDecision::updateOrCreate(
            ['player_a_id' => $a, 'player_b_id' => $b],
            [
                'status' => PlayerDuplicateDecision::DISMISSED,
                'player_a_snapshot' => PlayerDuplicateDecision::snapshot($players[$a]),
                'player_b_snapshot' => PlayerDuplicateDecision::snapshot($players[$b]),
                'kept_player_id' => null,
                'score' => $result['score'],
                'reasons' => $result['reasons'],
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ],
        );

        return back()->with('success', 'flash.dup.dismissed');
    }

    public function restore(PlayerDuplicateDecision $decision): RedirectResponse
    {
        abort_unless($decision->status === PlayerDuplicateDecision::DISMISSED, 404);

        $decision->delete();

        return back()->with('success', 'flash.dup.restored');
    }

    /** Dismissed or merged decisions, newest first, filtered like the live list. */
    private function decided(string $status, array $criteria, int $minScore): Collection
    {
        return PlayerDuplicateDecision::query()
            ->where('status', $status)
            ->latest('decided_at')
            ->latest('id')
            ->get()
            ->filter(fn (PlayerDuplicateDecision $d) => $d->score >= $minScore && DuplicateScorer::meets($d->reasons ?? [], $criteria))
            ->map(fn (PlayerDuplicateDecision $d) => [
                'a' => $d->player_a_id,
                'b' => $d->player_b_id,
                'score' => $d->score,
                'reasons' => $d->reasons ?? [],
                'status' => $d->status,
                'decision_id' => $d->id,
                'decided_at' => $d->decided_at?->toDateString(),
                'kept_player_id' => $d->kept_player_id,
                'snapshot_a' => $d->player_a_snapshot,
                'snapshot_b' => $d->player_b_snapshot,
            ])
            ->values();
    }
}
