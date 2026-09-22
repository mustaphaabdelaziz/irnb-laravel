<?php

namespace App\Http\Controllers;

use App\Models\EquipmentRental;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything currently out with someone, across every catalog, split into
 * temporary rentals and work assignments — the one place to see who holds
 * what and take it back.
 */
class EquipmentOutController extends Controller
{
    private const TYPES = ['rental', 'assignment'];

    public function __invoke(Request $request): Response
    {
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : 'rental';
        $search = trim((string) $request->query('search', ''));

        $open = EquipmentRental::query()->whereNull('return_date')
            ->when($search !== '', fn (Builder $q) => $this->applySearch($q, $search));

        $counts = (clone $open)
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $rentals = (clone $open)
            ->where('type', $type)
            ->with(['equipmentItem.catalog:id,name', 'rentable'])
            ->orderBy('checkout_date')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (EquipmentRental $rental) => [
                'id' => $rental->id,
                'type' => $rental->type,
                'recipient_name' => $rental->recipient_name,
                'player_id' => $rental->rentable instanceof Player ? $rental->rentable->id : null,
                'membership_id' => $rental->rentable instanceof Player ? $rental->rentable->membership_id : null,
                'external_phone' => $rental->external_phone,
                'catalog' => $rental->equipmentItem?->catalog
                    ? ['id' => $rental->equipmentItem->catalog->id, 'name' => $rental->equipmentItem->catalog->name]
                    : null,
                'item_label' => $rental->equipmentItem?->unique_identifier ?: $rental->equipmentItem?->designation,
                'quantity' => $rental->quantity,
                'returned_quantity' => $rental->returned_quantity,
                'outstanding_quantity' => $rental->outstanding_quantity,
                'checkout_date' => $rental->checkout_date?->toDateString(),
                'due_date' => $rental->due_date?->toDateString(),
                'is_overdue' => $rental->is_overdue,
            ]);

        return Inertia::render('Equipment/Out', [
            'rentals' => $rentals,
            'counts' => [
                'rental' => (int) ($counts['rental'] ?? 0),
                'assignment' => (int) ($counts['assignment'] ?? 0),
            ],
            'filters' => ['type' => $type, 'search' => $search],
        ]);
    }

    /** The holder-search condition, shared by the tab counts and the table so they never disagree. */
    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $w) => $w
            ->where('external_name', 'like', "%{$search}%")
            ->orWhereHasMorph('rentable', [Player::class], fn (Builder $p) => $p->search($search)));
    }
}
