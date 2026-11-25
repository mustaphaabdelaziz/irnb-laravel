<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Suppliers', [
            'suppliers' => Supplier::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request));

        return back()->with('success', 'flash.supplier_created');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', 'flash.supplier_updated');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->isInUse()) {
            return back()->with('error', 'flash.supplier_in_use');
        }
        $supplier->delete();

        return back()->with('success', 'flash.supplier_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge(['rib' => $request->filled('rib') ? preg_replace('/\s+/', '', (string) $request->input('rib')) : null]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) use ($supplier) {
                $taken = Supplier::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($supplier, fn ($q) => $q->whereKeyNot($supplier->id))
                    ->exists();
                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => __('validation.attributes.name')]));
                }
            }],
            'activity' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'rib' => ['nullable', 'string', 'regex:/^\d{10,30}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['name'] = trim($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
