<?php

namespace App\Http\Controllers;

use App\Models\StorageLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorageLocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/StorageLocations', [
            'locations' => StorageLocation::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:storage_locations,name'],
        ]);

        StorageLocation::create($data);

        return back()->with('success', 'Storage location created successfully.');
    }

    public function update(Request $request, StorageLocation $storageLocation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:storage_locations,name,'.$storageLocation->id],
        ]);

        $storageLocation->update($data);

        return back()->with('success', 'Storage location updated successfully.');
    }

    public function destroy(StorageLocation $storageLocation): RedirectResponse
    {
        $storageLocation->delete();

        return back()->with('success', 'Storage location deleted successfully.');
    }
}
