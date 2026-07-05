<?php

namespace App\Http\Controllers;

use App\Models\BoardTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BoardTermController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $term = BoardTerm::create($data);

        if ($term->is_current) {
            $this->makeCurrent($term);
        }

        return back()->with('success', 'Term created.');
    }

    public function update(Request $request, BoardTerm $boardTerm): RedirectResponse
    {
        $data = $this->validateData($request);
        $boardTerm->update($data);

        if ($boardTerm->is_current) {
            $this->makeCurrent($boardTerm);
        }

        return back()->with('success', 'Term updated.');
    }

    public function destroy(BoardTerm $boardTerm): RedirectResponse
    {
        if ($boardTerm->members()->exists()) {
            return back()->with('error', 'Cannot delete: term has board members.');
        }

        $boardTerm->delete();

        return back()->with('success', 'Term deleted.');
    }

    /** Only one term can be current. */
    private function makeCurrent(BoardTerm $term): void
    {
        BoardTerm::where('id', '!=', $term->id)->update(['is_current' => false]);
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['boolean'],
        ]);
    }
}
