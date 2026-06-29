<?php

namespace App\Http\Controllers;

use App\Models\MemberJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberJobController extends Controller
{
    public function index(): Response
    {
        $jobs = MemberJob::query()->orderBy('name')->get();

        return Inertia::render('Settings/Jobs', [
            'jobs' => $jobs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:member_jobs,name'],
            'description' => ['nullable', 'string'],
        ]);

        MemberJob::create($validated);

        return back()->with('success', 'Job created successfully.');
    }

    public function update(Request $request, MemberJob $job): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:member_jobs,name,'.$job->id],
            'description' => ['nullable', 'string'],
        ]);

        $job->update($validated);

        return back()->with('success', 'Job updated successfully.');
    }

    public function destroy(MemberJob $job): RedirectResponse
    {
        $job->delete();

        return back()->with('success', 'Job deleted successfully.');
    }
}
