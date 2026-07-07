<?php

namespace App\Http\Controllers;

use App\Http\Requests\Equipment\RentEquipmentRequest;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Equipment\EquipmentLifecycleService;
use App\Services\Equipment\SerialNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentItemController extends Controller
{
    public function __construct(
        private EquipmentLifecycleService $lifecycle,
        private SerialNumberService $serials,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'catalog_id' => ['required', 'integer', 'exists:equipment_catalogs,id'],
            'designation' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['required', 'date'],
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $item = new EquipmentItem([
                'catalog_id' => $validated['catalog_id'],
                'designation' => $validated['designation'] ?? null,
                'purchase_date' => $validated['purchase_date'],
                'condition' => $validated['condition'] ?? 'New',
                'location' => $validated['location'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Assigns unique_identifier and saves (with collision retry).
            $this->serials->assign($item);

            if (! empty($validated['purchase_price'])) {
                $purchaseTransaction = Transaction::create([
                    'amount' => $validated['purchase_price'],
                    'transaction_date' => $validated['purchase_date'],
                    'transaction_type' => 'expense',
                    'category' => 'equipment',
                    'description' => 'Equipment purchase: '.$item->unique_identifier,
                    'recorded_by_user_id' => $request->user()?->id,
                    'status' => 'Paid',
                    'fiscal_year' => now()->year,
                ]);

                $item->purchase_transaction_id = $purchaseTransaction->id;
                $item->save();
            }
        });

        return redirect()->route('equipment.catalogs.show', $validated['catalog_id'])
            ->with('success', 'Equipment item added successfully.');
    }

    public function previewSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'catalog_id' => ['required', 'integer', 'exists:equipment_catalogs,id'],
            'purchase_date' => ['required', 'date'],
        ]);

        return response()->json([
            'serial' => $this->serials->previewNext($validated['catalog_id'], $validated['purchase_date']),
        ]);
    }

    public function update(Request $request, EquipmentItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'designation' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['required', 'date'],
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        // Serial (unique_identifier) is immutable identity — never touched here.
        $item->update($validated);

        return back()->with('success', 'Equipment item updated successfully.');
    }

    public function destroy(EquipmentItem $item): RedirectResponse
    {
        if ($item->status === 'Rented' || $item->activeRental) {
            return back()->with('error', 'Cannot delete a rented item. Return it first.');
        }

        $catalogId = $item->catalog_id;

        // Explicitly remove all cascade-child rows (rentals, histories, inventory
        // session lines) so deletion is deterministic regardless of DB FK
        // enforcement. The purchase Transaction is intentionally kept. Note: a
        // past inventory session's stored `total_expected` count is not
        // retroactively decremented.
        DB::transaction(function () use ($item) {
            $item->rentals()->delete();
            $item->histories()->delete();
            DB::table('inventory_session_items')->where('equipment_item_id', $item->id)->delete();
            $item->delete();
        });

        return redirect()->route('equipment.catalogs.show', $catalogId)
            ->with('success', 'Equipment item deleted successfully.');
    }

    public function rent(RentEquipmentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $item = EquipmentItem::findOrFail($validated['equipment_item_id']);

        $rentable = match ($validated['rentable_type']) {
            'Player' => Player::findOrFail($validated['rentable_id']),
            'User' => User::findOrFail($validated['rentable_id']),
        };

        $this->lifecycle->rentOut(
            $item,
            $rentable,
            $validated['due_date'] ?? null,
            $request->user()?->id,
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Equipment rented successfully.');
    }

    public function returnItem(Request $request, EquipmentRental $rental): RedirectResponse
    {
        $request->validate([
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->lifecycle->returnItem(
            $rental,
            $request->user()?->id,
            $request->input('condition', 'Good'),
            $request->input('notes'),
        );

        return back()->with('success', 'Equipment returned successfully.');
    }

    public function repair(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate(['notes' => ['nullable', 'string']]);

        $this->lifecycle->sendToRepair($item, $request->user()?->id, $request->input('notes'));

        return back()->with('success', 'Equipment sent to repair.');
    }

    public function completeRepair(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate([
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
        ]);

        $this->lifecycle->completeRepair($item, $request->user()?->id, $request->input('condition', 'Good'));

        return back()->with('success', 'Equipment repair completed.');
    }

    public function markLost(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate(['notes' => ['nullable', 'string']]);

        $this->lifecycle->markAsLost($item, $request->user()?->id, $request->input('notes'));

        return back()->with('success', 'Equipment marked as lost.');
    }

    public function inventory(): Response
    {
        $statusCounts = EquipmentItem::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $summary = [
            'total' => (int) $statusCounts->sum(),
            'available' => (int) ($statusCounts['Available'] ?? 0),
            'rented' => (int) ($statusCounts['Rented'] ?? 0),
            'under_repair' => (int) ($statusCounts['Under Repair'] ?? 0),
            'lost' => (int) ($statusCounts['Lost'] ?? 0),
            'retired' => (int) ($statusCounts['Retired'] ?? 0),
        ];

        $conditionBreakdown = EquipmentItem::query()
            ->selectRaw('condition, COUNT(*) as count')
            ->groupBy('condition')
            ->orderBy('condition')
            ->get()
            ->map(fn ($row) => ['condition' => $row->condition, 'count' => (int) $row->count])
            ->values();

        $categoryBreakdown = DB::table('equipment_items')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->groupBy('equipment_catalogs.category')
            ->selectRaw("equipment_catalogs.category as category, COUNT(*) as total,
                SUM(CASE WHEN equipment_items.status = 'Available' THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN equipment_items.status = 'Rented' THEN 1 ELSE 0 END) as rented")
            ->orderBy('equipment_catalogs.category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total' => (int) $row->total,
                'available' => (int) $row->available,
                'rented' => (int) $row->rented,
            ])
            ->values();

        $overdueRentals = EquipmentRental::query()
            ->with(['equipmentItem.catalog', 'rentable'])
            ->whereNull('return_date')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->get()
            ->map(fn (EquipmentRental $rental) => [
                'id' => $rental->id,
                'unique_identifier' => $rental->equipmentItem?->unique_identifier,
                'due_date' => $rental->due_date?->toDateString(),
                'catalog' => $rental->equipmentItem?->catalog
                    ? ['name' => $rental->equipmentItem->catalog->name]
                    : null,
                'rented_to' => $rental->rentable
                    ? ['firstname' => $rental->rentable->firstname, 'lastname' => $rental->rentable->lastname]
                    : null,
            ])
            ->values();

        return Inertia::render('Equipment/Inventory', [
            'summary' => $summary,
            'conditionBreakdown' => $conditionBreakdown,
            'categoryBreakdown' => $categoryBreakdown,
            'overdueRentals' => $overdueRentals,
        ]);
    }

    public function history(EquipmentItem $item): Response
    {
        $item->load(['catalog', 'activeRental.rentable']);
        $rentable = $item->activeRental?->rentable;

        return Inertia::render('Equipment/History', [
            'item' => [
                'id' => $item->id,
                'unique_identifier' => $item->unique_identifier,
                'designation' => $item->designation,
                'status' => $item->status,
                'condition' => $item->condition,
                'location' => $item->location,
                'due_date' => $item->activeRental?->due_date?->toDateString(),
                'catalog' => $item->catalog
                    ? ['id' => $item->catalog->id, 'name' => $item->catalog->name]
                    : null,
                'rented_to' => $rentable instanceof Player
                    ? ['id' => $rentable->id, 'firstname' => $rentable->firstname, 'lastname' => $rentable->lastname]
                    : null,
            ],
            'history' => $item->histories()
                ->with('user:id,name')
                ->orderByDesc('event_timestamp')
                ->get()
                ->map(fn (EquipmentHistory $event) => [
                    'id' => $event->id,
                    'event_type' => str_replace(' ', '_', strtolower((string) $event->event_type)),
                    'details' => $event->details,
                    'created_at' => $event->event_timestamp?->toDateTimeString(),
                    'user' => $event->user?->name,
                ]),
        ]);
    }
}
