<?php

namespace App\Http\Controllers;

use App\Http\Requests\Equipment\StoreEquipmentCatalogRequest;
use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\Player;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Export\ExcelExporter;
use App\Services\Storage\FileStorageService;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EquipmentCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        // items_count is the number of lots; units_total is the number of
        // physical units, which is the figure that means something to a user.
        $query = EquipmentCatalog::query()
            ->withCount('items')
            ->withSum('items as units_total', 'quantity');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $catalogs = $query->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Equipment/Catalog/Index', [
            'catalogs' => $catalogs,
            'filters' => $request->only(['search', 'category']),
            'equipmentCategories' => EquipmentCategory::orderBy('name')->pluck('name'),
        ]);
    }

    public function show(EquipmentCatalog $catalog): Response
    {
        // The page links to the per-item history endpoint rather than rendering
        // histories inline, so don't over-fetch items.histories here.
        // Rentals are loaded because availability derives from them; branches
        // because each lot shows its tags.
        $catalog->load(['items.activeRental.rentable', 'items.rentals', 'items.branches']);
        $catalog->items->each->append('available_quantity');

        return Inertia::render('Equipment/Catalog/Show', [
            'catalog' => $catalog,
            // Units, not rows: sum what each lot can still issue.
            'availableCount' => $catalog->items->sum(fn (EquipmentItem $item) => $item->available_quantity),
            'totalQuantity' => (int) $catalog->items->sum('quantity'),
            'storageLocations' => StorageLocation::orderBy('name')->pluck('name'),
            'branches' => Branch::orderBy('name')->get()
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized_name]),
            // For the rent dropdown: identify players by name + membership id, not a raw id.
            'players' => Player::where('archived', false)->orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                    'birthdate' => $p->birthdate?->toDateString(),
                    'branch_ids' => $p->branches->pluck('id'),
                ]),
            // Equipment can be assigned to staff, not only lent to players.
            'users' => User::where('is_active', true)->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'fullname' => $u->name]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Equipment/Catalog/Create', [
            'equipmentCategories' => EquipmentCategory::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(StoreEquipmentCatalogRequest $request, FileStorageService $files): RedirectResponse
    {
        $data = $request->validated();
        unset($data['picture']);

        if ($request->hasFile('picture')) {
            $stored = $files->storeImage($request->file('picture'), 'equipment', 800);
            $data['picture_url'] = $stored['url'];
            $data['picture_filename'] = $stored['filename'];
        }

        $catalog = EquipmentCatalog::create($data);

        return redirect()->route('equipment.catalogs.show', $catalog)
            ->with('success', 'flash.equipment_catalog_created');
    }

    public function edit(EquipmentCatalog $catalog): Response
    {
        return Inertia::render('Equipment/Catalog/Edit', [
            // items_count drives the tracking-mode lock in the form.
            'catalog' => $catalog->loadCount('items'),
            'equipmentCategories' => EquipmentCategory::orderBy('name')->pluck('name'),
        ]);
    }

    public function update(StoreEquipmentCatalogRequest $request, EquipmentCatalog $catalog, FileStorageService $files): RedirectResponse
    {
        $data = $request->validated();
        unset($data['picture']);

        // Switching tracking mode with stock on hand would strand that stock
        // in a shape the new mode cannot express, so it is locked once the
        // catalog holds lots.
        if ($catalog->items()->exists()) {
            unset($data['requires_serial']);
        }

        if ($request->hasFile('picture')) {
            $files->delete($catalog->picture_filename);
            $stored = $files->storeImage($request->file('picture'), 'equipment', 800);
            $data['picture_url'] = $stored['url'];
            $data['picture_filename'] = $stored['filename'];
        }

        $catalog->update($data);

        return redirect()->route('equipment.catalogs.show', $catalog)
            ->with('success', 'flash.equipment_catalog_updated');
    }

    public function destroy(EquipmentCatalog $catalog): RedirectResponse
    {
        $catalog->delete();

        return redirect()->route('equipment.catalogs.index')
            ->with('success', 'flash.equipment_catalog_deleted');
    }

    /**
     * Import columns shared by the template and the importer.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const IMPORT_COLUMNS = [
        ['name', 'الاسم', 'كرة مباراة'],
        ['category', 'الفئة', 'Balls'],
        ['brand', 'العلامة التجارية', 'Adidas'],
        ['purchase_price', 'سعر الشراء', ''],
        ['description', 'الوصف', ''],
    ];

    public function importTemplate(): StreamedResponse
    {
        $headers = array_map(fn ($column) => $column[1], self::IMPORT_COLUMNS);
        $example = array_map(fn ($column) => $column[2], self::IMPORT_COLUMNS);

        return Csv::download('equipment-catalogs-template.csv', $headers, [$example]);
    }

    public function export(ExcelExporter $exporter): StreamedResponse
    {
        $rows = EquipmentCatalog::query()
            ->withSum('items as units_total', 'quantity')
            ->orderBy('name')->get()
            ->map(fn (EquipmentCatalog $c) => [
                $c->name,
                $c->category,
                $c->brand,
                (string) $c->purchase_price,
                (int) $c->units_total,
                $c->description,
            ]);

        // Units, not rows: one lot row can hold 100 dossards.
        $headers = ['name', 'category', 'brand', 'purchase_price', 'total_units', 'description'];

        return $exporter->download('Equipment catalogs', $headers, $rows->all(),
            'equipment-catalogs-'.now()->format('Y-m-d').'.csv');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        try {
            $rows = Csv::readRows($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        array_shift($rows); // drop the header row

        // Resolve categories case-insensitively to their canonical name.
        $categories = EquipmentCategory::pluck('name')
            ->mapWithKeys(fn ($name) => [mb_strtolower(trim($name)) => $name]);

        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $data = $this->mapImportRow($row);

            if (($data['name'] ?? '') === '') {
                continue; // blank line
            }

            $category = $categories[mb_strtolower((string) $data['category'])] ?? null;
            if ($category === null) {
                $errors[] = __('Row :line: unknown category ":category".', ['line' => $line, 'category' => $data['category']]);

                continue;
            }

            if (EquipmentCatalog::where('name', $data['name'])->exists()) {
                $errors[] = __('Row :line: ":name" already exists — skipped.', ['line' => $line, 'name' => $data['name']]);

                continue;
            }

            EquipmentCatalog::create([
                'name' => $data['name'],
                'category' => $category,
                'brand' => $data['brand'] ?: null,
                'description' => $data['description'] ?: null,
                'purchase_price' => is_numeric($data['purchase_price']) ? (float) $data['purchase_price'] : null,
            ]);
            $imported++;
        }

        $message = __(':count equipments imported successfully.', ['count' => $imported]);

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
}
