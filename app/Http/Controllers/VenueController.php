<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VenueController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Venues', [
            'venues' => Venue::query()->withCount('games')->orderByDesc('is_home')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Venue::create($this->validated($request));

        return back()->with('success', 'flash.venue_created');
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        $venue->update($this->validated($request, $venue));

        return back()->with('success', 'flash.venue_updated');
    }

    /** Matches played there keep their record; only the venue link is cleared. */
    public function destroy(Venue $venue): RedirectResponse
    {
        $venue->delete();

        return back()->with('success', 'flash.venue_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Venue $venue = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('venues', 'name')->ignore($venue)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_home' => ['sometimes', 'boolean'],
        ]);
        $data['is_home'] = $request->boolean('is_home');

        return $data;
    }
}
