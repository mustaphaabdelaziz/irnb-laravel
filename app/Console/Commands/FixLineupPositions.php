<?php

namespace App\Console\Commands;

use App\Models\Formation;
use App\Models\GamePlayer;
use App\Models\Position;
use App\Services\Competitions\SlotPositions;
use Illuminate\Console\Command;

/**
 * One-off repair: a slot labelled ST (and CM, LW…) used to save no position,
 * so starters were left without one. Each starter that has a slot and no
 * position gets the position its slot stands for, by the same slot map the
 * line-up save uses. Positions already set are never touched.
 */
class FixLineupPositions extends Command
{
    protected $signature = 'competitions:fix-lineup-positions {--dry-run : Count what would be filled without writing} {--chunk=500 : Rows read per page}';

    protected $description = 'Fill the position of starters that have a slot but no position, from the game\'s formation';

    public function handle(SlotPositions $slotPositions): int
    {
        $dry = (bool) $this->option('dry-run');
        // Paged by id: the filter column is being filled, so offset paging would skip rows.
        $chunk = max(1, (int) $this->option('chunk'));
        $filled = 0;
        $noPosition = 0;
        $noSlot = 0;
        $formations = [];
        $positions = [];

        GamePlayer::query()
            ->where('role', 'starter')->whereNotNull('slot')->whereNull('position_id')
            ->whereHas('game', fn ($q) => $q->whereNotNull('formation_id'))
            ->with('game.team:id,branch_id')
            ->eachById(function (GamePlayer $gp) use ($slotPositions, $dry, &$filled, &$noPosition, &$noSlot, &$formations, &$positions) {
                $formationId = $gp->game->formation_id;
                // array_key_exists: a deleted formation (null) is remembered too.
                if (! array_key_exists($formationId, $formations)) {
                    $formations[$formationId] = Formation::find($formationId);
                }
                $branchId = $gp->game->team->branch_id;
                $positions[$branchId] ??= Position::query()->where('branch_id', $branchId)->pluck('id', 'abbreviation');

                $label = $formations[$formationId]?->slots[$gp->slot]['label'] ?? null;
                if ($label === null) {
                    $noSlot++;

                    return;
                }
                $id = $slotPositions->resolve((string) $label, $positions[$branchId]);
                if ($id === null) {
                    $noPosition++;

                    return;
                }
                if (! $dry) {
                    $gp->update(['position_id' => $id]);
                }
                $filled++;
            }, $chunk);

        $this->info(($dry ? 'Would fill ' : 'Filled ')."{$filled} starter position(s). Left empty: {$noPosition} (the branch has no matching position), {$noSlot} (formation or slot no longer exists).");

        return self::SUCCESS;
    }
}
