<?php

namespace App\Http\Controllers;

use App\Models\SportingStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SportingStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/SportingStatuses', [
            'sportingStatuses' => SportingStatus::withCount('registrations')
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SportingStatus::create($this->validated($request));

        return back()->with('success', 'flash.sporting_status_created');
    }

    public function update(Request $request, SportingStatus $sportingStatus): RedirectResponse
    {
        $sportingStatus->update($this->validated($request, $sportingStatus));

        return back()->with('success', 'flash.sporting_status_updated');
    }

    public function destroy(SportingStatus $sportingStatus): RedirectResponse
    {
        if ($sportingStatus->registrations()->exists()) {
            return back()->with('error', 'flash.sporting_status_in_use');
        }

        $sportingStatus->delete();

        return back()->with('success', 'flash.sporting_status_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SportingStatus $existing = null): array
    {
        $unique = 'unique:sporting_statuses,name'.($existing ? ','.$existing->id : '');

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $unique],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
