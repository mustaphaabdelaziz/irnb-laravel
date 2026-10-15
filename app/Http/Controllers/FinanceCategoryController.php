<?php

namespace App\Http\Controllers;

use App\Models\FinanceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The finance-categories manager (CategoryManager.vue), opened from the
 * Transactions page and from Finance settings.
 *
 * Names come per language. The stored base `name` — the unique key and the
 * fallback for any language left empty — is the one sent, else English, else
 * French, else Arabic, so a category typed in Arabic only stays Arabic
 * everywhere. Built-in categories (is_system) keep their base name, code and type:
 * the app finds them by code and name (LegacyFinanceCategory,
 * finance:backfill). They can be renamed per language, never deleted.
 */
class FinanceCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['name'] = $this->baseName($data);
        $this->assertNameFree($data['type'], $data['name']);

        FinanceCategory::create($data + ['is_active' => true, 'is_system' => false]);

        return back()->with('success', 'flash.category_added');
    }

    public function update(Request $request, FinanceCategory $financeCategory): RedirectResponse
    {
        $data = $this->validateData($request);

        // Entries already booked keep their side of the books.
        if ($financeCategory->is_system || $financeCategory->transactions()->exists()) {
            $data['type'] = $financeCategory->type;
        }

        if ($financeCategory->is_system) {
            // The app finds built-in categories by code and base name.
            unset($data['name'], $data['code']);
        } else {
            $data['name'] = $this->baseName($data);
            $this->assertNameFree($data['type'], $data['name'], $financeCategory->id);
        }

        $financeCategory->update($data);

        return back()->with('success', 'flash.category_updated');
    }

    public function destroy(FinanceCategory $financeCategory): RedirectResponse
    {
        if ($financeCategory->is_system) {
            return back()->with('error', 'flash.category_is_system');
        }

        $used = $financeCategory->transactions()->count();
        if ($used > 0) {
            return back()->with('error', ['key' => 'flash.category_has_transactions', 'params' => ['count' => $used]]);
        }

        $financeCategory->delete();

        return back()->with('success', 'flash.category_deleted');
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            // Legacy single name (older callers); the manager sends the three below.
            'name' => ['nullable', 'string', 'max:120'],
            // At least one language; the error sits on the form's first field.
            'name_ar' => ['nullable', 'string', 'max:120', 'required_without_all:name,name_fr,name_en'],
            'name_fr' => ['nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:16'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function baseName(array $data): string
    {
        foreach (['name', 'name_en', 'name_fr', 'name_ar'] as $field) {
            if (filled($data[$field] ?? null)) {
                return trim((string) $data[$field]);
            }
        }

        return '';
    }

    private function assertNameFree(string $type, string $name, ?int $ignoreId = null): void
    {
        $taken = FinanceCategory::query()
            ->where('type', $type)
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => __('A finance category with this name already exists.'),
            ]);
        }
    }
}
