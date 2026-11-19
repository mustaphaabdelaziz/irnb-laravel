<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\Player;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Equipment\EquipmentStockService;
use App\Support\Export;
use App\Support\UiLang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
            // From the highest number used this year: a count would reuse a
            // deleted session's successor and collide with the unique reference.
            $prefix = 'INV-'.$year.'-';
            $seq = InventorySession::query()
                ->where('reference', 'like', $prefix.'%')
                ->pluck('reference')
                ->map(fn (string $ref) => (int) substr($ref, strlen($prefix)))
                ->max() + 1;

            $session = InventorySession::create([
                'reference' => 'INV-'.$year.'-'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
                'type' => $data['type'],
                'session_date' => $data['session_date'],
                'status' => 'in_progress',
                'conducted_by_user_id' => $request->user()?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            // Only units in the club are counted: lost lots are already written
            // off, and units out on loan are with the borrower, not on a shelf.
            $stock = app(EquipmentStockService::class);
            $items = EquipmentItem::whereNotIn('status', ['Retired', 'Lost'])
                ->with(['rentals' => fn ($q) => $q->whereNull('return_date')])
                ->get(['id', 'status', 'condition', 'location', 'quantity']);

            $expectedTotal = 0;
            foreach ($items as $it) {
                $onLoan = min($it->quantity, $stock->outstandingQuantity($it));
                $expected = $it->quantity - $onLoan;
                if ($expected < 1) {
                    continue; // wholly on loan: nothing to find
                }

                InventorySessionItem::create([
                    'inventory_session_id' => $session->id,
                    'equipment_item_id' => $it->id,
                    'expected_status' => $it->status,
                    'expected_condition' => $it->condition,
                    'expected_location' => $it->location,
                    'expected_quantity' => $expected,
                    'on_loan_quantity' => $onLoan,
                ]);
                $expectedTotal += $expected;
            }
            // Expected is a number of units to find, not a number of lines.
            $session->update(['total_expected' => $expectedTotal]);

            ActivityRecorder::record($request->user(), ActivityAction::STOCKTAKE_STARTED, $session);

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

        $lines = $session->items()->pluck('expected_quantity', 'id');
        $errors = [];
        foreach ($data['items'] as $i => $row) {
            $max = $lines[$row['id']] ?? null;
            if ($max !== null && $row['found'] && isset($row['found_quantity']) && $row['found_quantity'] > $max) {
                $errors["items.$i.found_quantity"] = __('validation.max.numeric', ['attribute' => __('Found'), 'max' => $max]);
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        foreach ($data['items'] as $row) {
            InventorySessionItem::where('id', $row['id'])->where('inventory_session_id', $session->id)
                ->update([
                    'counted' => true,
                    'found' => $row['found'] && (($row['found_quantity'] ?? 1) > 0),
                    'found_quantity' => $row['found']
                        ? ($row['found_quantity'] ?? ($lines[$row['id']] ?? 1))
                        : 0,
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
    public function complete(Request $request, InventorySession $session): RedirectResponse
    {
        abort_if($session->status !== 'in_progress', 403, 'This inventory is already closed.');

        DB::transaction(function () use ($session, $request) {
            $found = 0;
            $missing = 0;
            $stock = app(EquipmentStockService::class);

            foreach ($session->items()->with(['item.rentals' => fn ($q) => $q->whereNull('return_date')])->get() as $line) {
                if (! $line->counted) {
                    continue;
                }

                // Units, not lines: a lot of 50 where 47 turned up is 47 found
                // and 3 missing, not one "found" tick.
                $foundUnits = $line->found_units;
                $missingUnits = max(0, $line->expected_quantity - $foundUnits);

                // Totals stay as computed from the sheet, whatever happens to stock.
                $found += $foundUnits;
                $missing += $missingUnits;

                // The item was lost or retired after the sheet was made: it has
                // already left stock, so the count must not condemn it again.
                if (! $line->item || in_array($line->item->status, ['Lost', 'Retired'], true)) {
                    continue;
                }

                if ($foundUnits > 0) {
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

                if ($missingUnits > 0) {
                    $inClub = max(0, $line->item->quantity - $stock->outstandingQuantity($line->item));
                    $toWriteOff = min($missingUnits, max(0, $inClub - $foundUnits));
                    if ($toWriteOff > 0) {
                        // Only the missing units are condemned; the rest stay in service.
                        $stock->writeOffMissing($line->item, $toWriteOff, $session->conducted_by_user_id);
                    }
                }
            }

            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
                'total_found' => $found,
                'total_missing' => $missing,
            ]);

            // Credited to whoever closes the count, not whoever opened it.
            ActivityRecorder::record($request->user(), ActivityAction::STOCKTAKE_COMPLETED, $session, [
                'found' => $found,
                'missing' => $missing,
            ]);
        });

        return back()->with('success', ['key' => 'flash.inventory_completed', 'params' => ['reference' => $session->reference]]);
    }

    public function export(Request $request, InventorySession $session): SymfonyResponse
    {
        $session->load('items.item.catalog:id,name');
        $rows = $session->items->map(function (InventorySessionItem $l) {
            $found = $l->counted ? UiLang::get($l->found ? 'col.found' : 'col.missing') : '-';

            return [
                $l->item?->unique_identifier, $l->item?->catalog?->name, EquipmentItem::stateLabel($l->expected_status),
                EquipmentItem::stateLabel($l->expected_condition), $l->expected_location, $found,
                $l->counted ? $l->found_units : '', $l->expected_quantity,
                EquipmentItem::stateLabel($l->actual_condition), $l->actual_location, $l->note,
            ];
        })->all();

        $headers = array_map(fn (string $key) => UiLang::get($key), [
            'col.item', 'col.catalog', 'col.expected_status', 'col.expected_condition', 'col.expected_location',
            'col.result', 'col.found_quantity', 'col.expected_quantity', 'col.actual_condition', 'col.actual_location', 'col.note',
        ]);

        return Export::download(Export::format($request), 'inventory-'.$session->reference, $headers, $rows,
            UiLang::get('inventory', 'Inventory').' '.$session->reference);
    }

    public function destroy(InventorySession $session): RedirectResponse
    {
        $session->delete();

        return redirect()->route('inventory.index')->with('success', 'flash.inventory_deleted');
    }
}
