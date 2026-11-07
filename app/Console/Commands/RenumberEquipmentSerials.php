<?php

namespace App\Console\Commands;

use App\Models\EquipmentItem;
use App\Services\Equipment\SerialNumberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time move to the per-catalog serial format. Serials used to be counted
 * per category and purchase year, so a catalog's units skipped numbers
 * (…-00017, …-00018, …-00055). This rewrites every serial to
 * {CLUB}-{YYYY}-{CODE}-{CCC}-{NNNNN}, numbering each catalog's units of a
 * purchase year 1, 2, 3… in purchase-date order (then creation order).
 * Running it again gives the same serials.
 */
class RenumberEquipmentSerials extends Command
{
    protected $signature = 'equipment:renumber-serials {--dry-run : List the changes without saving them}';

    protected $description = 'Renumber equipment unit serials so each catalog counts its own units';

    public function handle(SerialNumberService $serials): int
    {
        $items = EquipmentItem::query()
            ->whereNotNull('unique_identifier')
            ->with('catalog:id,category')
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get(['id', 'catalog_id', 'purchase_date', 'unique_identifier']);

        $counters = [];
        $changes = [];
        foreach ($items as $item) {
            $prefix = $serials->prefix($item);
            $counters[$prefix] = ($counters[$prefix] ?? 0) + 1;
            $serial = $serials->format($prefix, $counters[$prefix]);

            if ($serial !== $item->unique_identifier) {
                $changes[$item->id] = [$item->unique_identifier, $serial];
            }
        }

        foreach ($changes as [$old, $new]) {
            $this->line("{$old} → {$new}");
        }
        $this->info(count($changes).' of '.$items->count().' serials '.($this->option('dry-run') ? 'would change.' : 'changed.'));

        if ($this->option('dry-run') || $changes === []) {
            return self::SUCCESS;
        }

        // serials are unique: park every changed row on a temporary value
        // first, or a new serial could collide with an old one not yet moved.
        DB::transaction(function () use ($changes) {
            foreach (array_keys($changes) as $id) {
                DB::table('equipment_items')->where('id', $id)->update(['unique_identifier' => "RENUMBER-{$id}"]);
            }
            foreach ($changes as $id => [, $new]) {
                DB::table('equipment_items')->where('id', $id)->update(['unique_identifier' => $new]);
            }
        });

        return self::SUCCESS;
    }
}
