<?php

namespace App\Http\Controllers;

use App\Models\EquipmentCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/EquipmentCategories', [
            'categories' => EquipmentCategory::query()
                ->withCount('catalogs')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:equipment_categories,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        EquipmentCategory::create($validated);

        return back()->with('success', 'Category created successfully.');
    }

    public function update(Request $request, EquipmentCategory $equipmentCategory): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:equipment_categories,name,'.$equipmentCategory->id],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // Catalogs reference the category by name, so a rename must cascade.
        if ($validated['name'] !== $equipmentCategory->name) {
            $equipmentCategory->catalogs()->update(['category' => $validated['name']]);
        }

        $equipmentCategory->update($validated);

        return back()->with('success', 'Category updated successfully.');
    }

    public function destroy(EquipmentCategory $equipmentCategory): RedirectResponse
    {
        if ($equipmentCategory->catalogs()->exists()) {
            return back()->with('error', 'Cannot delete: category is used by equipment catalogs.');
        }

        $equipmentCategory->delete();

        return back()->with('success', 'Category deleted successfully.');
    }
}
