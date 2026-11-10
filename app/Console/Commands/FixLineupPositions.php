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
    protected $signature = 'competitions:fix-lineup-positions {--dry-run : Count what would be filled without writing}';

    protected $description = 'Fill the position of starters that have a slot but no position, from the game\'s formation';

    public function handle(SlotPositions $slotPositions): int
    {
        $dry = (bool) $this->option('dry-run');
        $filled = 0;
        $skipped = 0;
        $formations = [];
        $positions = [];

        GamePlayer::query()
            ->where('role', 'starter')->whereNotNull('slot')->whereNull('position_id')
            ->whereHas('game', fn ($q) => $q->whereNotNull('formation_id'))
            ->with('game.team:id,branch_id')
            ->orderBy('id')
            ->each(function (GamePlayer $gp) use ($slotPositions, $dry, &$filled, &$skipped, &$formations, &$positions) {
                $formations[$gp->game->formation_id] ??= Formation::find($gp->game->formation_id);
                $branchId = $gp->game->team->branch_id;
                $positions[$branchId] ??= Position::query()->where('branch_id', $branchId)->pluck('id', 'abbreviation');

                $label = $formations[$gp->game->formation_id]?->slots[$gp->slot]['label'] ?? null;
                $id = $label === null ? null : $slotPositions->resolve((string) $label, $positions[$branchId]);
                if ($id === null) {
                    $skipped++;

                    return;
                }
                if (! $dry) {
                    $gp->update(['position_id' => $id]);
                }
                $filled++;
            });

        $this->info(($dry ? 'Would fill ' : 'Filled ')."{$filled} starter position(s); {$skipped} left empty (no matching position).");

        return self::SUCCESS;
    }
}
