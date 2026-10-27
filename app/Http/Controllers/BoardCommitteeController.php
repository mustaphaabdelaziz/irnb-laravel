<?php

namespace App\Http\Controllers;

use App\Models\BoardCommittee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** App Configuration list of board committees (Finance, Sports, Media…). */
class BoardCommitteeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/BoardCommittees', [
            'committees' => BoardCommittee::query()
                ->withCount('members')
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        BoardCommittee::create($request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:board_committees,name'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]));

        return back()->with('success', 'flash.committee_created');
    }

    public function update(Request $request, BoardCommittee $boardCommittee): RedirectResponse
    {
        $boardCommittee->update($request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('board_committees', 'name')->ignore($boardCommittee->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]));

        return back()->with('success', 'flash.committee_updated');
    }

    /** A committee that still seats members (any term) is history: keep it. */
    public function destroy(BoardCommittee $boardCommittee): RedirectResponse
    {
        if ($boardCommittee->members()->exists()) {
            return back()->with('error', 'flash.committee_in_use');
        }

        $boardCommittee->delete();

        return back()->with('success', 'flash.committee_deleted');
    }
}
