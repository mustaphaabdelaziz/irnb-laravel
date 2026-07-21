<?php

namespace App\Http\Controllers;

use App\Exceptions\Equipment\StockException;
use App\Http\Requests\Equipment\ReceiveStockRequest;
use App\Http\Requests\Equipment\RentEquipmentRequest;
use App\Http\Requests\Equipment\SplitLotRequest;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\Transaction;
use App\Services\Equipment\EquipmentLifecycleService;
use App\Services\Equipment\EquipmentStockService;
use App\Services\Equipment\SerialNumberService;
use App\Services\Export\ExcelExporter;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EquipmentItemController extends Controller
{
    public function __construct(
        private EquipmentLifecycleService $lifecycle,
        private SerialNumberService $serials,
    ) {}

    /** Bring stock into the club. The only path that can spend money. */
    public function receive(ReceiveStockRequest $request, EquipmentStockService $stock): RedirectResponse
    {
        $item = $stock->receive($request->validated(), $request->user()?->id);

        return redirect()->route('equipment.catalogs.show', $item->catalog_id)
            ->with('success', 'flash.equipment_stock_received');
    }

    /** Reclassify part of a lot — "3 of these 20 balls are punctured". */
    public function split(SplitLotRequest $request, EquipmentItem $item, EquipmentStockService $stock): RedirectResponse
    {
        try {
            $stock->splitLot(
                $item,
                (int) $request->validated('quantity'),
                $request->validated('condition'),
                $request->user()?->id,
                $request->validated('notes'),
            );
        } catch (StockException $e) {
            return back()->with('error', $e->toFlash());
        }

        return back()->with('success', 'flash.equipment_lot_split');
    }

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

        DB::transaction(function () use ($validated) {
            $item = new EquipmentItem([
                'catalog_id' => $validated['catalog_id'],
                'designation' => $validated['designation'] ?? null,
                'purchase_date' => $validated['purchase_date'],
                'condition' => $validated['condition'] ?? 'New',
                'location' => $validated['location'] ?? null,
                'notes' => $validated['notes'] ?? null,
                // The price records what the unit is worth. It no longer
                // spends money: purchases go through receive-stock, where
                // recording the expense is an explicit choice.
                'unit_price' => $validated['purchase_price'] ?? null,
            ]);

            // Assigns unique_identifier and saves (with collision retry).
            $this->serials->assign($item);
        });

        return redirect()->route('equipment.catalogs.show', $validated['catalog_id'])
            ->with('success', 'flash.equipment_item_added');
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

        return back()->with('success', 'flash.equipment_item_updated');
    }

    public function destroy(EquipmentItem $item): RedirectResponse
    {
        if ($item->status === 'Rented' || $item->activeRental) {
            return back()->with('error', 'flash.item_is_rented');
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
            ->with('success', 'flash.equipment_item_deleted');
    }

    public function rent(RentEquipmentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $item = EquipmentItem::findOrFail($validated['equipment_item_id']);

        // Player from the team, or a free-text external person (no account).
        $rentable = $validated['rentable_type'] === 'Player'
            ? Player::findOrFail($validated['rentable_id'])
            : null;

        // The due date is derived from the expected rent period, so overdue
        // detection has a concrete date to compare against.
        $checkout = $validated['checkout_date'] ?? now()->toDateString();
        $dueDate = ! empty($validated['expected_days'])
            ? Carbon::parse($checkout)->addDays((int) $validated['expected_days'])->toDateString()
            : null;

        try {
            $this->lifecycle->rentOut($item, $rentable, [
                'quantity' => (int) ($validated['quantity'] ?? 1),
                'type' => $validated['type'] ?? 'rental',
                'checkout_date' => $checkout,
                'due_date' => $dueDate,
                'external_name' => $validated['external_name'] ?? null,
                'external_phone' => $validated['external_phone'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()?->id,
            ]);
        } catch (StockException $e) {
            return back()->with('error', $e->toFlash());
        }

        return back()->with('success', 'flash.equipment_issued');
    }

    public function returnItem(Request $request, EquipmentRental $rental): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
            'return_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $this->lifecycle->returnItem($rental, [
                'quantity' => $validated['quantity'] ?? null,
                'condition' => $validated['condition'] ?? 'Good',
                'return_date' => $validated['return_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()?->id,
            ]);
        } catch (StockException $e) {
            return back()->with('error', $e->toFlash());
        }

        return back()->with('success', 'flash.equipment_returned');
    }

    public function repair(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate(['notes' => ['nullable', 'string']]);

        $this->lifecycle->sendToRepair($item, $request->user()?->id, $request->input('notes'));

        return back()->with('success', 'flash.equipment_sent_to_repair');
    }

    public function completeRepair(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate([
            'condition' => ['nullable', 'string', 'in:New,Good,Fair,Poor,Damaged'],
        ]);

        $this->lifecycle->completeRepair($item, $request->user()?->id, $request->input('condition', 'Good'));

        return back()->with('success', 'flash.equipment_repair_completed');
    }

    public function markLost(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate(['notes' => ['nullable', 'string']]);

        $this->lifecycle->markAsLost($item, $request->user()?->id, $request->input('notes'));

        return back()->with('success', 'flash.equipment_marked_as_lost');
    }

    public function markFound(Request $request, EquipmentItem $item): RedirectResponse
    {
        $request->validate(['notes' => ['nullable', 'string']]);

        $this->lifecycle->markAsFound($item, $request->user()?->id, $request->input('notes'));

        return back()->with('success', 'flash.equipment_found');
    }

    public function inventory(): Response
    {
        // Units, not rows: one lot row can hold 100 dossards.
        $statusQuantities = EquipmentItem::query()
            ->selectRaw('status, SUM(quantity) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        // "Rented" cannot come from the status column any more: a lot of 20
        // with 10 units issued is still status Available. It comes from the
        // open rentals instead.
        $unitsOut = (int) EquipmentRental::query()
            ->whereNull('return_date')
            ->selectRaw('COALESCE(SUM(quantity - returned_quantity), 0) as out_units')
            ->value('out_units');

        $unblockedUnits = (int) $statusQuantities
            ->reject(fn ($quantity, $status) => in_array($status, EquipmentStockService::BLOCKING_STATUSES, true))
            ->sum();

        $summary = [
            'total' => (int) $statusQuantities->sum(),
            'available' => max(0, $unblockedUnits - $unitsOut),
            'rented' => $unitsOut,
            'under_repair' => (int) ($statusQuantities['Under Repair'] ?? 0),
            'lost' => (int) ($statusQuantities['Lost'] ?? 0),
            'retired' => (int) ($statusQuantities['Retired'] ?? 0),
        ];

        $conditionBreakdown = EquipmentItem::query()
            ->selectRaw('condition, SUM(quantity) as count')
            ->groupBy('condition')
            ->orderBy('condition')
            ->get()
            ->map(fn ($row) => ['condition' => $row->condition, 'count' => (int) $row->count])
            ->values();

        $blockedList = "'".implode("','", EquipmentStockService::BLOCKING_STATUSES)."'";

        $categoryBreakdown = DB::table('equipment_items')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->leftJoin(DB::raw('(SELECT equipment_item_id, SUM(quantity - returned_quantity) AS out_units
                FROM equipment_rentals WHERE return_date IS NULL GROUP BY equipment_item_id) rentals_out'),
                'rentals_out.equipment_item_id', '=', 'equipment_items.id')
            ->groupBy('equipment_catalogs.category')
            ->selectRaw("equipment_catalogs.category as category,
                SUM(equipment_items.quantity) as total,
                SUM(CASE WHEN equipment_items.status IN ({$blockedList}) THEN 0
                    ELSE equipment_items.quantity - COALESCE(rentals_out.out_units, 0) END) as available,
                COALESCE(SUM(rentals_out.out_units), 0) as rented")
            ->orderBy('equipment_catalogs.category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total' => (int) $row->total,
                'available' => (int) $row->available,
                'rented' => (int) $row->rented,
            ])
            ->values();

        // Asset value: quantity x what a unit cost, falling back to the
        // catalog's reference value when a lot has no price of its own.
        $totalValue = (float) DB::table('equipment_items')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->whereNotIn('equipment_items.status', ['Lost', 'Retired'])
            ->selectRaw('COALESCE(SUM(equipment_items.quantity *
                COALESCE(equipment_items.unit_price, equipment_catalogs.purchase_price, 0)), 0) as value')
            ->value('value');

        // Value per branch. Club-wide lots have no branch row, so they are
        // reported separately rather than silently dropped by the join.
        $valueByBranch = DB::table('branch_equipment_item')
            ->join('branches', 'branches.id', '=', 'branch_equipment_item.branch_id')
            ->join('equipment_items', 'equipment_items.id', '=', 'branch_equipment_item.equipment_item_id')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->whereNotIn('equipment_items.status', ['Lost', 'Retired'])
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('branches.name as branch,
                SUM(equipment_items.quantity) as units,
                SUM(equipment_items.quantity *
                    COALESCE(equipment_items.unit_price, equipment_catalogs.purchase_price, 0)) as value')
            ->orderBy('branches.name')
            ->get()
            ->map(fn ($row) => [
                'branch' => $row->branch,
                'units' => (int) $row->units,
                'value' => (float) $row->value,
            ])
            ->values();

        $clubWide = DB::table('equipment_items')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->whereNotIn('equipment_items.status', ['Lost', 'Retired'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('branch_equipment_item')
                ->whereColumn('branch_equipment_item.equipment_item_id', 'equipment_items.id'))
            ->selectRaw('COALESCE(SUM(equipment_items.quantity), 0) as units,
                COALESCE(SUM(equipment_items.quantity *
                    COALESCE(equipment_items.unit_price, equipment_catalogs.purchase_price, 0)), 0) as value')
            ->first();

        $overdueRentals = EquipmentRental::query()
            ->with(['equipmentItem.catalog', 'rentable'])
            ->whereNull('return_date')
            ->whereNotNull('due_date')
            // Assignments are open-ended and can never be late.
            ->where('type', '!=', 'assignment')
            ->whereDate('due_date', '<', now())
            ->get()
            ->map(fn (EquipmentRental $rental) => [
                'id' => $rental->id,
                'unique_identifier' => $rental->equipmentItem?->unique_identifier,
                'due_date' => $rental->due_date?->toDateString(),
                'catalog' => $rental->equipmentItem?->catalog
                    ? ['name' => $rental->equipmentItem->catalog->name]
                    : null,
                'quantity' => $rental->outstanding_quantity,
                // A team player or an external person — recipient_name covers both.
                'rented_to' => $rental->recipient_name ? ['name' => $rental->recipient_name] : null,
            ])
            ->values();

        return Inertia::render('Equipment/Inventory', [
            'summary' => $summary,
            'totalValue' => $totalValue,
            'valueByBranch' => $valueByBranch,
            'clubWideValue' => ['units' => (int) $clubWide->units, 'value' => (float) $clubWide->value],
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

    /**
     * Import columns shared by the template and the importer.
     * Each entry: [field key, Arabic header, example value]. The catalog is implicit
     * (the page the file is uploaded from); serials + status are assigned automatically.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const IMPORT_COLUMNS = [
        ['designation', 'التسمية', 'قميص رقم 10'],
        ['purchase_date', 'تاريخ الشراء (YYYY-MM-DD)', '2026-05-01'],
        ['condition', 'الحالة (New/Good/Fair/Poor/Damaged)', 'New'],
        ['location', 'الموقع', 'المخزن A'],
        ['purchase_price', 'سعر الشراء', ''],
        ['notes', 'ملاحظات', ''],
    ];

    public function importTemplate(): StreamedResponse
    {
        $headers = array_map(fn ($column) => $column[1], self::IMPORT_COLUMNS);
        $example = array_map(fn ($column) => $column[2], self::IMPORT_COLUMNS);

        return Csv::download('equipment-items-template.csv', $headers, [$example]);
    }

    public function export(EquipmentCatalog $catalog, ExcelExporter $exporter): StreamedResponse
    {
        $rows = $catalog->items()->orderBy('unique_identifier')->get()->map(fn (EquipmentItem $item) => [
            $item->unique_identifier,
            $item->designation,
            $item->condition,
            $item->location,
            $item->status,
            $item->purchase_date?->toDateString(),
            $item->notes,
        ]);

        $headers = ['serial', 'designation', 'condition', 'location', 'status', 'purchase_date', 'notes'];

        return $exporter->download('Equipment '.$catalog->name, $headers, $rows->all(),
            'equipment-'.$catalog->id.'-'.now()->format('Y-m-d').'.csv');
    }

    public function import(Request $request, EquipmentCatalog $catalog): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        try {
            $rows = Csv::readRows($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        array_shift($rows); // drop the header row

        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $data = $this->mapImportRow($row);

            // Skip fully-blank lines (an item needs no designation, but an empty row is noise).
            if (collect($data)->every(fn ($v) => $v === null || $v === '')) {
                continue;
            }

            try {
                DB::transaction(function () use ($data, $catalog, $request) {
                    $item = new EquipmentItem([
                        'catalog_id' => $catalog->id,
                        'designation' => $data['designation'] ?: null,
                        'purchase_date' => $this->parseDate($data['purchase_date']) ?? now()->toDateString(),
                        'condition' => in_array($data['condition'], ['New', 'Good', 'Fair', 'Poor', 'Damaged'], true)
                            ? $data['condition'] : 'New',
                        'location' => $data['location'] ?: null,
                        'notes' => $data['notes'] ?: null,
                    ]);

                    $this->serials->assign($item);

                    if (is_numeric($data['purchase_price']) && (float) $data['purchase_price'] > 0) {
                        $transaction = Transaction::create([
                            'amount' => (float) $data['purchase_price'],
                            'transaction_date' => $item->purchase_date,
                            'transaction_type' => 'expense',
                            'category' => 'equipment',
                            'description' => 'Equipment purchase: '.$item->unique_identifier,
                            'recorded_by_user_id' => $request->user()?->id,
                            'status' => 'Paid',
                            'fiscal_year' => now()->year,
                        ]);
                        $item->purchase_transaction_id = $transaction->id;
                        $item->save();
                    }
                });

                $imported++;
            } catch (Throwable $e) {
                $errors[] = __('Row :line: :message', ['line' => $line, 'message' => $e->getMessage()]);
            }
        }

        $message = __(':count items imported successfully.', ['count' => $imported]);

        if ($errors !== []) {
            return back()->with('success', $message)->with('error', implode("\n", array_slice($errors, 0, 10)));
        }

        return back()->with('success', $message);
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, string|null>
     */
    private function mapImportRow(array $row): array
    {
        $data = [];
        foreach (self::IMPORT_COLUMNS as $index => [$key]) {
            $value = $row[$index] ?? null;
            $data[$key] = is_string($value) ? trim($value) : ($value === null ? null : trim((string) $value));
        }

        return $data;
    }

    private function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
