<?php

namespace App\Http\Controllers;

use App\Http\Requests\Equipment\StoreEquipmentCatalogRequest;
use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\StorageLocation;
use App\Services\Dashboard\ModuleStats;
use App\Services\Storage\FileStorageService;
use App\Support\Export;
use App\Support\Import\ImportColumns;
use App\Support\ListFilter;
use App\Support\Spreadsheet;
use App\Support\UiLang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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

        if ($categories = ListFilter::values($request, 'category')) {
            $query->whereIn('category', $categories);
        }

        $catalogs = $query->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Equipment/Catalog/Index', [
            'catalogs' => $catalogs,
            'strip' => fn (): array => app(ModuleStats::class)->equipment(),
            'filters' => ListFilter::echo($request, ['category' => ListFilter::TEXT], ['search']),
            // Closure so filter reloads (partial) skip the lookup query.
            'equipmentCategories' => fn () => EquipmentCategory::orderBy('name')->pluck('name'),
        ]);
    }

    public function show(EquipmentCatalog $catalog): Response
    {
        // The page links to the per-item history endpoint rather than rendering
        // histories inline, so don't over-fetch items.histories here.
        // Rentals are loaded because availability derives from them; branches
        // because each lot shows its tags.
        // openRentals (with holders) so every person a lot is out with is listed,
        // not just the latest one.
        $catalog->load(['items.openRentals.rentable', 'items.rentals', 'items.branches']);
        $catalog->items->each->append('available_quantity');

        return Inertia::render('Equipment/Catalog/Show', [
            'catalog' => $catalog,
            // Units, not rows: sum what each lot can still issue.
            'availableCount' => $catalog->items->sum(fn (EquipmentItem $item) => $item->available_quantity),
            'totalQuantity' => (int) $catalog->items->sum('quantity'),
            'storageLocations' => StorageLocation::orderBy('name')->pluck('name'),
            'branches' => Branch::orderBy('name')->get()
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized_name]),
            'financeAccounts' => FinanceAccount::selectable()->get(),
            // For the rent dropdown: identify players by name + membership id, not a raw id.
            // branches is eager-loaded because the cross-branch warning reads
            // branch_ids for every player — without it this maps into an N+1.
            'players' => Player::where('archived', false)
                ->with('branches:id')
                ->orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                    'birthdate' => $p->birthdate?->toDateString(),
                    'branch_ids' => $p->branches->pluck('id'),
                ]),
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

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // exists on the array itself: one whereIn query, not one per id.
            'ids' => ['required', 'array', 'min:1', 'max:500', 'exists:equipment_catalogs,id'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $catalogs = EquipmentCatalog::whereIn('id', $validated['ids'])->get();

        DB::transaction(function () use ($catalogs) {
            $catalogs->each->delete();
        });

        return redirect()->route('equipment.catalogs.index')
            ->with('success', [
                'key' => 'flash.equipment_catalogs_deleted',
                'params' => ['count' => $catalogs->count()],
            ]);
    }

    /**
     * Import columns shared by the template and the importer. Found by header
     * (ar / fr / en, or `legacy`: the old Arabic template's header), else by
     * this position — so keep the order.
     *
     * @var list<array{key:string, label:string, legacy:list<string>, example:string}>
     */
    private const IMPORT_COLUMNS = [
        ['key' => 'name', 'label' => 'col.name', 'legacy' => ['الاسم'], 'example' => 'كرة مباراة'],
        ['key' => 'category', 'label' => 'col.category', 'legacy' => ['الفئة'], 'example' => 'Balls'],
        ['key' => 'brand', 'label' => 'col.brand', 'legacy' => ['العلامة التجارية'], 'example' => 'Adidas'],
        ['key' => 'purchase_price', 'label' => 'col.purchase_price', 'legacy' => ['سعر الشراء'], 'example' => ''],
        ['key' => 'description', 'label' => 'col.description', 'legacy' => ['الوصف'], 'example' => ''],
    ];

    public function importTemplate(Request $request): SymfonyResponse
    {
        return Export::download(Export::format($request), 'equipment-catalogs-template',
            (new ImportColumns(self::IMPORT_COLUMNS))->headers(), [array_column(self::IMPORT_COLUMNS, 'example')]);
    }

    public function export(Request $request): SymfonyResponse
    {
        $rows = EquipmentCatalog::query()
            ->withSum('items as units_total', 'quantity')
            ->orderBy('name')->get()
            ->map(fn (EquipmentCatalog $c) => [
                $c->name,
                // Catalogs store the category name as free text (no localized name).
                $c->category,
                $c->brand,
                $c->purchase_price !== null ? (float) $c->purchase_price : null,
                (int) $c->units_total,
                $c->description,
            ]);

        // Units, not rows: one lot row can hold 100 dossards.
        $headers = array_map(fn (string $key) => UiLang::get($key), [
            'col.name', 'col.category', 'col.brand', 'col.purchase_price', 'col.total_units', 'col.description',
        ]);

        return Export::download(Export::format($request), 'equipment-catalogs-'.now()->format('Y-m-d'),
            $headers, $rows->all(), UiLang::get('equipments', 'Equipment catalogs'));
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']]);

        try {
            $rows = Spreadsheet::readRows($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        // Skip an export's title/spacer rows and the header row itself.
        [$headerRow, $map] = (new ImportColumns(self::IMPORT_COLUMNS))->locate($rows);
        $rows = array_values(array_slice($rows, $headerRow + 1));

        // Resolve categories case-insensitively to their canonical name.
        $categories = EquipmentCategory::pluck('name')
            ->mapWithKeys(fn ($name) => [mb_strtolower(trim($name)) => $name]);

        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $headerRow + $i + 2; // the real spreadsheet row number
            $data = $this->mapImportRow($row, $map);

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
     * @param  array<string, int>  $map  column key => cell index (a column may be absent)
     * @return array<string, string|null>
     */
    private function mapImportRow(array $row, array $map): array
    {
        $data = [];
        foreach (self::IMPORT_COLUMNS as ['key' => $key]) {
            $value = $row[$map[$key] ?? -1] ?? null;
            $data[$key] = is_string($value) ? trim($value) : ($value === null ? null : trim((string) $value));
        }

        return $data;
    }
}
