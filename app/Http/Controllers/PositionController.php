<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Position;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Positions', [
            'positions' => Position::query()->with('branch')->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Position::create($this->validated($request));

        return back()->with('success', 'flash.position_created');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $position->update($this->validated($request, $position));

        return back()->with('success', 'flash.position_updated');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();

        return back()->with('success', 'flash.position_deleted');
    }

    /** Names and abbreviations are unique within a sport: football and handball each have their GK. */
    private function validated(Request $request, ?Position $position = null): array
    {
        $request->merge(['abbreviation' => strtoupper((string) $request->input('abbreviation'))]);
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $sameSport = fn (Builder $q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId);

        return $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('positions', 'name')->where($sameSport)->ignore($position)],
            'abbreviation' => ['required', 'string', 'max:16', Rule::unique('positions', 'abbreviation')->where($sameSport)->ignore($position)],
        ]);
    }
}
