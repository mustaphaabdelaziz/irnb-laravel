<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\TrainingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $categories = Category::query()
            ->withCount('players')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Categories', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        Category::create($validated);

        return back()->with('success', 'flash.category_created');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name,'.$category->id],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $category->update($validated);

        return back()->with('success', 'flash.category_updated');
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
