<?php

namespace App\Http\Controllers;

use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(): Response
    {
        $positions = Position::query()->orderBy('name')->get();

        return Inertia::render('Settings/Positions', [
            'positions' => $positions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:positions,name'],
            'abbreviation' => ['required', 'string', 'max:16', 'unique:positions,abbreviation'],
        ]);

        $validated['abbreviation'] = strtoupper($validated['abbreviation']);

        Position::create($validated);

        return back()->with('success', 'Position created successfully.');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:positions,name,'.$position->id],
            'abbreviation' => ['required', 'string', 'max:16', 'unique:positions,abbreviation,'.$position->id],
        ]);

        $validated['abbreviation'] = strtoupper($validated['abbreviation']);

        $position->update($validated);

        return back()->with('success', 'Position updated successfully.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();

        return back()->with('success', 'Position deleted successfully.');
    }
}
