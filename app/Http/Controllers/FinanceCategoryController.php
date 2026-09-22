<?php

namespace App\Http\Controllers;

use App\Models\FinanceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        FinanceCategory::create($data + ['is_active' => true, 'is_system' => false]);

        return back()->with('success', 'flash.category_added');
    }

    public function update(Request $request, FinanceCategory $financeCategory): RedirectResponse
    {
        $data = $this->validateData($request, $financeCategory);
        $financeCategory->update($data);

        return back()->with('success', 'flash.category_updated');
    }

    public function destroy(FinanceCategory $financeCategory): RedirectResponse
    {
        if ($financeCategory->transactions()->exists()) {
            return back()->with('error', 'flash.category_has_transactions');
        }

        $financeCategory->delete();

        return back()->with('success', 'flash.category_deleted');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request, ?FinanceCategory $existing = null): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'name' => ['required', 'string', 'max:120', Rule::unique('finance_categories', 'name')
                ->where('type', $request->input('type'))->ignore($existing?->id)],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'name_fr' => ['nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:16'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
