<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebsiteConfig\UpdateWebsiteConfigRequest;
use App\Models\WebsiteConfig;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteConfigController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/General', [
            'config' => WebsiteConfig::singleton(),
            'themePresets' => Theme::presetsForFrontend(),
            'defaultTheme' => config('themes.default', 'emerald'),
        ]);
    }

    public function update(UpdateWebsiteConfigRequest $request): RedirectResponse
    {
        $config = WebsiteConfig::singleton();

        $validated = $request->validated();
        $validated['last_modified_by_user_id'] = $request->user()?->id;

        // Settings forms submit a subset of keys; merge into the existing JSON so
        // unrelated keys (currencySymbol, dateFormat, fiscalYearStart, ...) are preserved.
        if (isset($validated['settings']) && is_array($validated['settings'])) {
            $validated['settings'] = array_merge($config->settings ?? [], $validated['settings']);
        }

        // Branding (logo/favicon uploads + the chosen color theme) is merged into
        // the existing JSON so a save from one sub-form never wipes the others.
        $branding = $config->branding ?? [];
        $changed = false;

        foreach (['logo', 'favicon'] as $field) {
            if ($request->hasFile($field)) {
                $path = $request->file($field)->store('branding', 'public');
                $branding[$field] = Storage::disk('public')->url($path);
                $changed = true;
            }
        }

        if ($request->filled('theme')) {
            $branding['theme'] = $request->input('theme');
            if ($request->input('theme') === 'custom' && Theme::isValidHex($request->input('primary_color'))) {
                $branding['primary'] = '#'.ltrim($request->input('primary_color'), '#');
            }
            $changed = true;
        }

        if ($changed) {
            $validated['branding'] = $branding;
        }

        // These are folded into `branding` above, not real columns.
        unset($validated['theme'], $validated['primary_color']);

        $config->update($validated);

        return back()->with('success', 'Configuration updated successfully.');
    }

    /**
     * Persist just the chosen club color. Called the moment a swatch is picked so
     * the selection survives a hard refresh without a separate "Save" click. Kept
     * flash-free to avoid a success toast on every pick.
     */
    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'max:32'],
            'primary_color' => ['nullable', 'string', 'regex:/^#?[0-9a-fA-F]{6}$/'],
        ]);

        $config = WebsiteConfig::singleton();
        $branding = $config->branding ?? [];
        $branding['theme'] = $validated['theme'];

        if ($validated['theme'] === 'custom' && Theme::isValidHex($validated['primary_color'] ?? null)) {
            $branding['primary'] = '#'.ltrim($validated['primary_color'], '#');
        }

        $config->update([
            'branding' => $branding,
            'last_modified_by_user_id' => $request->user()?->id,
        ]);

        return back(303);
    }
}
