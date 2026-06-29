<?php

namespace App\Http\Controllers;

use App\Http\Requests\Equipment\StoreEquipmentCatalogRequest;
use App\Models\EquipmentCatalog;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = EquipmentCatalog::query()->withCount('items');

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
        ]);
    }

    public function show(EquipmentCatalog $catalog): Response
    {
        // The page links to the per-item history endpoint rather than rendering
        // histories inline, so don't over-fetch items.histories here.
        $catalog->load(['items.activeRental.rentable']);

        return Inertia::render('Equipment/Catalog/Show', [
            'catalog' => $catalog,
            // Count from the already-loaded collection (avoids an extra COUNT query).
            'availableCount' => $catalog->items->where('status', 'Available')->count(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Equipment/Catalog/Create');
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
            ->with('success', 'Equipment catalog created successfully.');
    }

    public function edit(EquipmentCatalog $catalog): Response
    {
        return Inertia::render('Equipment/Catalog/Edit', [
            'catalog' => $catalog,
        ]);
    }

    public function update(StoreEquipmentCatalogRequest $request, EquipmentCatalog $catalog, FileStorageService $files): RedirectResponse
    {
        $data = $request->validated();
        unset($data['picture']);

        if ($request->hasFile('picture')) {
            $files->delete($catalog->picture_filename);
            $stored = $files->storeImage($request->file('picture'), 'equipment', 800);
            $data['picture_url'] = $stored['url'];
            $data['picture_filename'] = $stored['filename'];
        }

        $catalog->update($data);

        return redirect()->route('equipment.catalogs.show', $catalog)
            ->with('success', 'Equipment catalog updated successfully.');
    }

    public function destroy(EquipmentCatalog $catalog): RedirectResponse
    {
        $catalog->delete();

        return redirect()->route('equipment.catalogs.index')
            ->with('success', 'Equipment catalog deleted successfully.');
    }
}
