<?php

namespace App\Http\Controllers;

use App\Models\BoardRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoardRoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/BoardRoles', [
            'roles' => BoardRole::query()
                ->withCount('members')
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:board_roles,name'],
            'label' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        BoardRole::create($validated);

        return back()->with('success', 'Role created.');
    }

    public function update(Request $request, BoardRole $boardRole): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:board_roles,name,'.$boardRole->id],
            'label' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Members reference the role by name, so a rename must cascade.
        if ($validated['name'] !== $boardRole->name) {
            $boardRole->members()->update(['role' => $validated['name']]);
        }

        $boardRole->update($validated);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(BoardRole $boardRole): RedirectResponse
    {
        if ($boardRole->members()->exists()) {
            return back()->with('error', 'Cannot delete: role is assigned to board members.');
        }

        $boardRole->delete();

        return back()->with('success', 'Role deleted.');
    }
}
