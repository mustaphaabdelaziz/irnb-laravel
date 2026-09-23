<?php

namespace App\Http\Controllers;

use App\Models\MemberJob;
use App\Services\Lookup\JobDuplicateFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MemberJobController extends Controller
{
    public function __construct(private JobDuplicateFinder $duplicates) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Jobs', [
            // The counts let the page warn before a delete that cannot happen.
            'jobs' => MemberJob::query()->withCount(['players', 'users'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        if ($existing = $this->duplicates->exact($validated)) {
            return back()->withErrors([
                'name' => __('This job already exists as ":name".', ['name' => $existing->name]),
            ])->withInput();
        }

        MemberJob::create($validated);

        return back()->with('success', 'flash.job_created');
    }

    /**
     * Create a job from inside the player form and hand it straight back, so a
     * half-filled member form is never lost to a page reload.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        if ($existing = $this->duplicates->exact($validated)) {
            return response()->json(['duplicate' => $existing], 409);
        }

        $job = MemberJob::create($validated);

        return response()->json([
            'job' => $job,
            'similar' => $this->duplicates->similar($validated, $job->id)->values(),
        ], 201);
    }

    public function update(Request $request, MemberJob $job): RedirectResponse
    {
        $validated = $this->validated($request, $job);

        if ($existing = $this->duplicates->exact($validated, $job->id)) {
            return back()->withErrors([
                'name' => __('This job already exists as ":name".', ['name' => $existing->name]),
            ])->withInput();
        }

        $job->update($validated);

        return back()->with('success', 'flash.job_updated');
    }

    /** Move every member of one job to another, then drop the duplicate. */
    public function merge(Request $request, MemberJob $job): RedirectResponse
    {
        $validated = $request->validate([
            'into' => ['required', 'integer', 'exists:member_jobs,id'],
        ]);

        if ((int) $validated['into'] === $job->id) {
            return back()->withErrors(['into' => __('Choose a different job to merge into.')]);
        }

        DB::transaction(function () use ($job, $validated) {
            $job->players()->update(['member_job_id' => $validated['into']]);
            $job->users()->update(['member_job_id' => $validated['into']]);
            $job->delete();
        });

        return back()->with('success', 'flash.job_merged');
    }

    public function destroy(MemberJob $job): RedirectResponse
    {
        // The FK blanks the job on every member it is attached to, silently.
        if ($job->players()->exists() || $job->users()->exists()) {
            return back()->with('error', 'flash.job_in_use');
        }

        $job->delete();

        return back()->with('success', 'flash.job_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MemberJob $existing = null): array
    {
        $unique = 'unique:member_jobs,name'.($existing ? ','.$existing->id : '');

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $unique],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
