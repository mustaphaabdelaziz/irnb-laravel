<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerDuplicateDecision;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Player\Duplicates\DuplicateFinder;
use App\Services\Player\Merge\FieldMerge;
use App\Services\Player\Merge\MergeClashes;
use App\Services\Player\Merge\MergePreview;
use App\Services\Player\Merge\PlayerMerger;
use App\Services\Player\Merge\PreMergeBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Side-by-side comparison of a duplicate pair and the merge itself. */
class PlayerMergeController extends Controller
{
    public function show(Request $request, FieldMerge $fields, MergeClashes $clashes, MergePreview $preview, DuplicateFinder $finder): Response|RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer'],
            'b' => ['required', 'integer', 'different:a'],
            'keeper' => ['nullable', 'integer'],
            'view' => ['nullable', 'boolean'],
        ]);
        $players = $this->pair((int) $data['a'], (int) $data['b']);
        if ($players === null) {
            return redirect()->route('players.duplicates.index')->with('error', 'flash.dup.gone');
        }
        [$keeper, $duplicate] = $this->sides($players, isset($data['keeper']) ? (int) $data['keeper'] : null);
        [$a, $b] = PlayerDuplicateDecision::orderedPair($keeper->id, $duplicate->id);

        return Inertia::render('Players/MergeDuplicates', [
            'a' => $a,
            'b' => $b,
            'keeper' => PlayerDuplicateDecision::snapshot($keeper),
            'duplicate' => PlayerDuplicateDecision::snapshot($duplicate),
            'fields' => $fields->diff($keeper, $duplicate),
            'counts' => (object) $preview->counts($duplicate->id),
            'clashes' => $clashes->find($keeper->id, $duplicate->id),
            'pair' => $finder->scorePair($a, $b),
            'viewOnly' => (bool) ($data['view'] ?? false),
        ]);
    }

    public function store(Request $request, PreMergeBackup $backup, PlayerMerger $merger, MergePreview $preview): RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer'],
            'b' => ['required', 'integer', 'different:a'],
            'keeper' => ['required', 'integer', Rule::in([(int) $request->input('a'), (int) $request->input('b')])],
            'choices' => ['nullable', 'array'],
            'choices.*' => [Rule::in(['keeper', 'duplicate'])],
        ]);
        $players = $this->pair((int) $data['a'], (int) $data['b']);
        if ($players === null) {
            return redirect()->route('players.duplicates.index')->with('error', 'flash.dup.gone');
        }
        [$keeper, $duplicate] = $this->sides($players, (int) $data['keeper']);
        $moved = array_sum($preview->counts($duplicate->id));

        try {
            $backup->run();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'flash.dup.backup_failed');
        }

        try {
            $merger->merge($keeper, $duplicate, $data['choices'] ?? [], $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'flash.dup.merge_failed');
        }

        // The merge is done and committed: a failed log line must not report it as failed.
        try {
            ActivityRecorder::record($request->user(), ActivityAction::PLAYERS_MERGED, $keeper, [
                'merged_player_id' => $duplicate->id,
                'count' => $moved,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->route('players.show', $keeper)->with('success', 'flash.dup.merged');
    }

    /** Both players, or null when one no longer exists. */
    private function pair(int $a, int $b): ?Collection
    {
        $players = Player::with(['memberJob', 'wilaya'])->whereIn('id', [$a, $b])->get()->keyBy('id');

        return $players->count() === 2 ? $players : null;
    }

    /** @return array{0: Player, 1: Player} the chosen keeper, else the older record, and the other one */
    private function sides(Collection $players, ?int $keeperId): array
    {
        $keeper = $keeperId !== null && $players->has($keeperId)
            ? $players[$keeperId]
            : $players->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();

        return [$keeper, $players->first(fn (Player $p) => $p->id !== $keeper->id)];
    }
}
