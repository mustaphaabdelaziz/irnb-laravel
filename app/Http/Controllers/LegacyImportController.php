<?php

namespace App\Http\Controllers;

use App\Services\Legacy\LegacyAccessImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Superadmin: import the export of the old Access app (works on the desktop too, no command line). */
class LegacyImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('LegacyImport/Index', ['result' => $request->session()->get('legacyResult')]);
    }

    public function store(Request $request, LegacyAccessImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);

        if (! is_array($data) || ($data['format'] ?? null) !== LegacyAccessImporter::FORMAT) {
            throw ValidationException::withMessages(['file' => __('validation.mimes', ['attribute' => 'file', 'values' => LegacyAccessImporter::FORMAT])]);
        }

        $result = $importer->import($data, $request->user());

        return back()->with('legacyResult', $result)->with('success', 'flash.legacy_imported');
    }
}
