<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Team;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    /** Categories live on their branch's page. A club with no branches keeps this page. */
    public function index(): Response|RedirectResponse
    {
        $main = Branch::mainId() ?? Branch::query()->orderBy('id')->value('id');
        if ($main !== null) {
            return redirect()->route('categories.show', $main);
        }

        return Inertia::render('Settings/Categories', [
            'categories' => $this->listed(null),
        ]);
    }

    /** The Categories tab of a branch's page. */
    public function show(Branch $branch): Response
    {
        return Inertia::render('Settings/BranchDetail', [
            'branch' => $branch->only(['id', 'name', 'localized_name', 'logo_url']),
            'tab' => 'categories',
            'categories' => $this->listed($branch->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : (Branch::mainId() ?? Branch::query()->orderBy('id')->value('id'));

        Category::create($this->validateNames($request, $branchId) + ['branch_id' => $branchId]);

        return back()->with('success', 'flash.category_created');
    }

    /** A category can't move to another branch: its rows and prices stay with it. */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validateNames($request, $category->branch_id, $category->id));

        return back()->with('success', 'flash.category_updated');
    }

    /** Hidden from pickers for new choices, kept where stored, still in reports. */
    public function toggleActive(Category $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active ? 'flash.category_activated' : 'flash.category_deactivated');
    }

    /** @return Collection<int, Category> */
    private function listed(?int $branchId): Collection
    {
        return Category::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->withCount('registrations')
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> */
    private function validateNames(Request $request, ?int $branchId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->ignore($ignoreId)
                    ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId)),
            ],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }

    /**
     * `training_sessions.category_id` cascade-deletes on the category, which
     * would take a joint pre-season session down with it even when other
     * categories still take part. Every such session is handed to its lowest
     * other pivot category first, so only sessions that were this category's
     * alone are deleted along with it.
     */
    public function destroy(Category $category): RedirectResponse
    {
        // A team's age category cannot vanish under its competitions and matches.
        if (Team::where('category_id', $category->id)->exists()) {
            return back()->with('error', 'flash.category_has_teams');
        }
        // A registration's category is restricted: its history must not vanish.
        if (BranchRegistration::where('category_id', $category->id)->exists()) {
            return back()->with('error', 'flash.category_has_registrations');
        }
        DB::transaction(function () use ($category) {
            TrainingSession::where('category_id', $category->id)->with('categories')->get()
                ->each(function (TrainingSession $session) use ($category) {
                    $others = array_diff($session->categories->modelKeys(), [$category->id]);
                    if ($others !== []) {
                        $session->update(['category_id' => min($others)]);
                    }
                });

            $category->delete();
        });

        return back()->with('success', 'flash.category_deleted');
    }
}
