<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use App\Models\StorageLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return back()->with('success', 'flash.storage_location_created');
    }

    public function update(Request $request, StorageLocation $storageLocation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:storage_locations,name,'.$storageLocation->id],
        ]);

        DB::transaction(function () use ($storageLocation, $data) {
            $old = $storageLocation->name;
            $storageLocation->update($data);
            if ($old !== $data['name']) {
                EquipmentItem::where('location', $old)->update(['location' => $data['name']]);
            }
        });

        return back()->with('success', 'flash.storage_location_updated');
    }

    public function destroy(StorageLocation $storageLocation): RedirectResponse
    {
        $storageLocation->delete();

        return back()->with('success', 'flash.storage_location_deleted');
    }
}
