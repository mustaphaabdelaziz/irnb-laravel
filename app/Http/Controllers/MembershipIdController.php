<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\Player\MembershipIdConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembershipIdController extends Controller
{
    public function index(MembershipIdConversion $conversion): Response
    {
        $plan = $conversion->plan();

        return Inertia::render('Settings/MembershipIds', [
            'branches' => Branch::orderBy('name')->get(['id', 'code', 'name', 'name_ar', 'name_fr', 'name_en']),
            'count' => count($plan['changes']),
            'samples' => array_slice($plan['changes'], 0, 10),
            'yearChanges' => array_values(array_filter($plan['changes'], fn (array $c) => $c['year_changed'])),
            'skipped' => $plan['skipped'],
            'canUndo' => $conversion->canUndo(),
        ]);
    }

    public function convert(Request $request, MembershipIdConversion $conversion): RedirectResponse
    {
        return back()->with('success', [
            'key' => 'flash.membership_ids_converted',
            'params' => ['count' => $conversion->convert($request->user())],
        ]);
    }

    public function undo(Request $request, MembershipIdConversion $conversion): RedirectResponse
    {
        return back()->with('success', [
            'key' => 'flash.membership_ids_restored',
            'params' => ['count' => $conversion->undo($request->user())],
        ]);
    }
}
