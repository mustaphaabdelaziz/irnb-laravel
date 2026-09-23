<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::query()->withCount('users')->orderByDesc('is_system')->orderBy('key')->get(),
            'modules' => Role::MODULES,
            'actions' => Role::ACTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRole($request);
        $validated['key'] = $this->uniqueKey($validated['name']);
        $validated['permissions'] = $this->sanitisePermissions($validated['permissions'] ?? []);

        Role::create($validated);

        return back()->with('success', 'flash.role_created');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $this->validateRole($request);
        $validated['permissions'] = $this->sanitisePermissions($validated['permissions'] ?? []);

        // The superadmin system role always keeps full access.
        if ($role->key === 'superadmin') {
            $validated['permissions'] = Role::allPermissions();
        }
        unset($validated['key']); // key is immutable after creation

        $role->update($validated);

        return back()->with('success', 'flash.role_updated');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'flash.system_role_undeletable');
        }

        // Detaching happens via nullOnDelete on users.role_id.
        $role->delete();

        return back()->with('success', 'flash.role_deleted');
    }

    private function validateRole(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'name.fr' => ['nullable', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array', function ($attr, $value, $fail) {
                $unknownModules = array_diff(array_keys((array) $value), Role::MODULES);
                if ($unknownModules !== []) {
                    $fail('Unknown module in permissions: '.implode(', ', $unknownModules));
                }
                foreach ((array) $value as $actions) {
                    if (array_diff((array) $actions, Role::ACTIONS) !== []) {
                        $fail('Unknown action in permissions.');
                    }
                }
            }],
        ]);
    }

    private function sanitisePermissions(array $permissions): array
    {
        $clean = [];
        foreach ($permissions as $module => $actions) {
            if (! in_array($module, Role::MODULES, true)) {
                continue;
            }
            $actions = array_values(array_intersect(Role::ACTIONS, (array) $actions));
            if ($actions !== []) {
                $clean[$module] = $actions;
            }
        }

        return $clean;
    }

    private function uniqueKey(array $name): string
    {
        $base = Str::slug($name['en'] ?? $name['fr'] ?? $name['ar'] ?? 'role') ?: 'role';
        $key = $base;
        $i = 1;
        while (Role::where('key', $key)->exists()) {
            $key = $base.'-'.(++$i);
        }

        return $key;
    }
}
