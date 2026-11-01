<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/ActivityTypes', [
            'types' => ActivityType::query()->withCount('activities')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] ??= (int) ActivityType::max('sort_order') + 1;
        ActivityType::create($data);

        return back()->with('success', 'flash.activity_type_created');
    }

    public function update(Request $request, ActivityType $activityType): RedirectResponse
    {
        $data = $this->validated($request, $activityType);
        if (($data['sort_order'] ?? null) === null) {
            unset($data['sort_order']);
        }
        $activityType->update($data);

        return back()->with('success', 'flash.activity_type_updated');
    }

    /** Activities keep their history: a type still in use is refused, never cascaded. */
    public function destroy(ActivityType $activityType): RedirectResponse
    {
        if ($activityType->activities()->exists()) {
            return back()->with('error', 'flash.activity_type_in_use');
        }

        $activityType->delete();

        return back()->with('success', 'flash.activity_type_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ActivityType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('activity_types', 'name')->ignore($type)],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
