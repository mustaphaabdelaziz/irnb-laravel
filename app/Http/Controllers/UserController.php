<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $query = User::query()->with('memberJob');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('membership_id', 'like', "%{$search}%");
            });
        }

        match ($request->input('status')) {
            'pending' => $query->where('approved', false),
            'approved' => $query->where('approved', true),
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        if ($request->filled('role')) {
            $query->whereJsonContains('privileges', $request->input('role'));
        }

        $users = $query->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'fullname' => $user->fullname,
                'email' => $user->email,
                'phones' => $user->phones,
                'picture_url' => $user->picture_url,
                'membership_id' => $user->membership_id,
                'privileges' => $user->privileges ?? [],
                'approved' => $user->approved,
                'is_active' => $user->is_active,
                'is_superadmin' => in_array('superadmin', $user->privileges ?? [], true),
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'status', 'role']),
            'pendingCount' => User::where('is_user', true)->where('approved', false)->count(),
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'phones' => $user->phones ?? [],
                'gender' => $user->gender,
                'picture_url' => $user->picture_url,
                'membership_id' => $user->membership_id,
                'privileges' => $user->privileges ?? [],
                'role_id' => $user->role_id,
                'permission_overrides' => $user->permission_overrides ?? ['grant' => [], 'revoke' => []],
                'approved' => $user->approved,
                'is_active' => $user->is_active,
                'is_user' => $user->is_user,
                'preferred_lng' => $user->preferred_lng,
                'is_superadmin' => in_array('superadmin', $user->privileges ?? [], true),
            ],
            'roles' => Role::orderByDesc('is_system')->orderBy('key')->get(['id', 'key', 'name', 'permissions']),
            'modules' => Role::MODULES,
            'actions' => Role::ACTIONS,
            'canManageAccess' => $request->user()->isSuperadmin(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, FileStorageService $files): RedirectResponse
    {
        $this->guardSuperadmin($request, $user);

        $validated = $request->validated();
        unset($validated['picture']);

        // Only a superadmin may (re)assign roles and per-user overrides.
        if (! $request->user()->isSuperadmin()) {
            unset($validated['role_id'], $validated['permission_overrides']);
        }

        // Preserve a superadmin's elevated privilege even if the form omits it.
        if (in_array('superadmin', $user->privileges ?? [], true)) {
            $validated['privileges'] = array_values(array_unique(['superadmin', ...($validated['privileges'] ?? [])]));
        }

        if ($request->hasFile('picture')) {
            $files->delete($user->picture_filename);
            $stored = $files->storeImage($request->file('picture'), 'users', 512);
            $validated['picture_url'] = $stored['url'];
            $validated['picture_filename'] = $stored['filename'];
        }

        $user->update($validated);

        return redirect()->route('users.index')
            ->with('success', 'flash.user_updated');
    }

    public function approve(User $user): RedirectResponse
    {
        $user->update(['approved' => true, 'is_active' => true]);

        return back()->with('success', 'flash.member_approved');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'flash.cannot_delete_self');
        }

        if (in_array('superadmin', $user->privileges ?? [], true)) {
            return back()->with('error', 'flash.superadmin_undeletable');
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'flash.user_deleted');
    }

    private function guardSuperadmin(Request $request, User $user): void
    {
        $editingSuperadmin = in_array('superadmin', $user->privileges ?? [], true);
        $actorIsSuperadmin = in_array('superadmin', $request->user()->privileges ?? [], true);

        if ($editingSuperadmin && ! $actorIsSuperadmin && $user->id !== $request->user()->id) {
            abort(403, 'Only a super administrator can modify another super administrator.');
        }
    }
}
