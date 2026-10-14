<?php

namespace App\Http\Controllers;

use App\Models\Opponent;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OpponentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Opponents', [
            'opponents' => Opponent::query()->withCount('games')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, FileStorageService $files): RedirectResponse
    {
        Opponent::create($this->validated($request, $files));

        return back()->with('success', 'flash.opponent_created');
    }

    public function update(Request $request, Opponent $opponent, FileStorageService $files): RedirectResponse
    {
        $data = $this->validated($request, $files, $opponent);
        if (isset($data['logo_filename'])) {
            $files->delete($opponent->logo_filename);
        }
        $opponent->update($data);

        return back()->with('success', 'flash.opponent_updated');
    }

    public function destroy(Opponent $opponent, FileStorageService $files): RedirectResponse
    {
        if ($opponent->games()->exists()) {
            return back()->with('error', 'flash.opponent_in_use');
        }
        $files->delete($opponent->logo_filename);
        $opponent->delete();

        return back()->with('success', 'flash.opponent_deleted');
    }

    /** @return array<string, mixed> the columns to save, with the stored logo when one was uploaded */
    private function validated(Request $request, FileStorageService $files, ?Opponent $opponent = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('opponents', 'name')->ignore($opponent)],
            'city' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);
        unset($data['logo']);

        if ($request->hasFile('logo')) {
            $stored = $files->storeImage($request->file('logo'), 'opponents', 256);
            $data['logo_url'] = $stored['url'];
            $data['logo_filename'] = $stored['filename'];
        }

        return $data;
    }
}
