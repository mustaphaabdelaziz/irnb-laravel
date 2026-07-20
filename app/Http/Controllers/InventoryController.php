<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\Player;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Equipment\EquipmentStockService;
use App\Services\Export\ExcelExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    private const CONDITIONS = ['New', 'Good', 'Fair', 'Poor', 'Damaged'];

    public function index(): Response
    {
        return Inertia::render('Inventory/Index', [
            'sessions' => InventorySession::withCount('items')
                ->with('conductedBy:id,name')
                ->orderByDesc('session_date')->orderByDesc('id')->get(),
            // Units awaiting a count, not lot rows.
            'itemCount' => (int) EquipmentItem::where('status', '!=', 'Retired')->sum('quantity'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['monthly', 'quarterly', 'yearly', 'ad_hoc'])],
            'session_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = DB::transaction(function () use ($data, $request) {
            $year = now()->format('Y');
            $seq = InventorySession::whereYear('created_at', $year)->count() + 1;

            $session = InventorySession::create([
                'reference' => 'INV-'.$year.'-'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
                'type' => $data['type'],
                'session_date' => $data['session_date'],
                'status' => 'in_progress',
                'conducted_by_user_id' => $request->user()?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            // Snapshot every non-retired item into the count sheet.
            $items = EquipmentItem::where('status', '!=', 'Retired')
                ->get(['id', 'status', 'condition', 'location', 'quantity']);

            foreach ($items as $it) {
                InventorySessionItem::create([
                    'inventory_session_id' => $session->id,
                    'equipment_item_id' => $it->id,
                    'expected_status' => $it->status,
                    'expected_condition' => $it->condition,
                    'expected_location' => $it->location,
                    // How many units of this lot the counter should find.
                    'expected_quantity' => $it->quantity,
                ]);
            }
            // Expected is a number of units to find, not a number of lots.
            $session->update(['total_expected' => (int) $items->sum('quantity')]);

            return $session;
        });

        return redirect()->route('inventory.show', $session)->with('success', ['key' => 'flash.inventory_started', 'params' => ['reference' => $session->reference]]);
    }

    public function show(InventorySession $session): Response
    {
        $session->load(['items.item.catalog:id,name,category', 'conductedBy:id,name', 'participants.participant']);

        return Inertia::render('Inventory/Session', [
            'session' => $session,
            'conditions' => self::CONDITIONS,
            'storageLocations' => StorageLocation::orderBy('name')->pluck('name'),
            'players' => Player::orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                ]),
        ]);
    }

    public function counts(Request $request, InventorySession $session): RedirectResponse
    {
        abort_if($session->status !== 'in_progress', 403, 'This inventory is already closed.');

        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.found' => ['required', 'boolean'],
            'items.*.found_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.actual_condition' => ['nullable', 'string', 'max:40'],
            'items.*.actual_location' => ['nullable', 'string', 'max:160'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['items'] as $row) {
            InventorySessionItem::where('id', $row['id'])->where('inventory_session_id', $session->id)
                ->update([
                    'counted' => true,
                    'found' => $row['found'],
                    'found_quantity' => $row['found'] ? ($row['found_quantity'] ?? null) : 0,
                    'actual_condition' => $row['actual_condition'] ?? null,
                    'actual_location' => $row['actual_location'] ?? null,
                    'note' => $row['note'] ?? null,
                ]);
        }

        return back()->with('success', 'flash.counts_saved');
    }

    public function participants(Request $request, InventorySession $session): RedirectResponse
    {
        abort_if($session->status !== 'in_progress', 403, 'This inventory is already closed.');

        $data = $request->validate([
            'participants' => ['present', 'array'],
            'participants.*.type' => ['required', Rule::in(['User', 'Player'])],
            'participants.*.id' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($session, $data) {
            $session->participants()->delete();

            $seen = [];
            foreach ($data['participants'] as $p) {
                $class = $p['type'] === 'User' ? User::class : Player::class;
                $key = $class.':'.$p['id'];
                if (isset($seen[$key]) || ! $class::whereKey($p['id'])->exists()) {
                    continue;
                }
                $seen[$key] = true;
                $session->participants()->create([
                    'participant_type' => $class,
                    'participant_id' => $p['id'],
                ]);
            }
        });

        return back()->with('success', 'flash.participants_updated');
    }

    /**
     * Reconcile: apply found/condition/location results back onto equipment
     * items (missing → Lost), then close the session.
     */
    public function complete(InventorySession $session): RedirectResponse
    {
        abort_if($session->status !== 'in_progress', 403, 'This inventory is already closed.');

        DB::transaction(function () use ($session) {
            $found = 0;
            $missing = 0;
            $stock = app(EquipmentStockService::class);

            foreach ($session->items()->with('item')->get() as $line) {
                if (! $line->counted) {
                    continue;
                }

                // Units, not lines: a lot of 50 where 47 turned up is 47 found
                // and 3 missing, not one "found" tick.
                $foundUnits = $line->found ? (int) ($line->found_quantity ?? $line->expected_quantity) : 0;
                $missingUnits = max(0, $line->expected_quantity - $foundUnits);

                $found += $foundUnits;
                $missing += $missingUnits;

                if ($line->item && $foundUnits > 0) {
                    $update = [];
                    if ($line->actual_condition && $line->actual_condition !== $line->item->condition) {
                        $update['condition'] = $line->actual_condition;
                    }
                    if ($line->actual_location && $line->actual_location !== $line->item->location) {
                        $update['location'] = $line->actual_location;
                    }
                    if ($update) {
                        $line->item->update($update);
                    }
                }

                if ($line->item && $missingUnits > 0) {
                    // Only the missing units are condemned; the rest stay in service.
                    $stock->writeOffMissing($line->item, $missingUnits, $session->conducted_by_user_id);
                }
            }

            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
                'total_found' => $found,
                'total_missing' => $missing,
            ]);
        });

        return back()->with('success', ['key' => 'flash.inventory_completed', 'params' => ['reference' => $session->reference]]);
    }

    public function export(InventorySession $session, ExcelExporter $exporter)
    {
        $session->load('items.item.catalog:id,name');
        $rows = $session->items->map(function (InventorySessionItem $l) {
            $found = $l->counted ? ($l->found ? 'Found' : 'Missing') : '-';

            return [
                $l->item?->unique_identifier, $l->item?->catalog?->name, $l->expected_status,
                $l->expected_condition, $l->expected_location, $found,
                $l->actual_condition, $l->actual_location, $l->note,
            ];
        })->all();

        $headers = ['Item', 'Catalog', 'Expected Status', 'Expected Condition', 'Expected Location', 'Result', 'Actual Condition', 'Actual Location', 'Note'];

        return $exporter->download('Inventory '.$session->reference, $headers, $rows, 'inventory-'.$session->reference.'.csv');
    }

    public function destroy(InventorySession $session): RedirectResponse
    {
        $session->delete();

        return redirect()->route('inventory.index')->with('success', 'flash.inventory_deleted');
    }
}
