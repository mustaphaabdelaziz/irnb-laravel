<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Support\BranchScope;
use App\Services\Dashboard\Support\MonthBucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Subscriptions and equipment: what has been collected, and what is out.
 *
 * Both halves answer the same kind of question — something was issued and
 * should come back, either as money or as a piece of kit.
 */
class OperationsStats
{
    /** Available quantity at or below this counts as low stock. */
    private const LOW_STOCK_THRESHOLD = 2;

    private const LOW_STOCK_ROWS = 8;

    /** Statuses that are not stock the club can use or lend. */
    private const NON_STOCK_STATUSES = ['Retired', 'Out of Service', 'Lost'];

    /**
     * Memoised low-stock rows.
     *
     * Both the summary tile and the list itself need them, and the query joins
     * three tables — computing it twice per request is pure waste.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $lowStockCache = null;

    public function get(DashboardFilters $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'subscriptionFunnel' => $this->subscriptionFunnel($filters),
            'itemsByStatus' => $this->itemsByStatus($filters),
            'lowStock' => $this->lowStock($filters),
            'rentalsPerMonth' => $this->rentalsPerMonth($filters),
            'inventory' => $this->inventory(),
        ];
    }

    private function summary(DashboardFilters $filters): array
    {
        $stock = $this->stockItems($filters)
            ->selectRaw('SUM(equipment_items.quantity * COALESCE(equipment_items.unit_price, 0)) as value, SUM(equipment_items.quantity) as units')
            ->first();

        $rentals = $this->openRentals($filters)
            ->selectRaw(
                'COUNT(*) as open_count, '
                .'COUNT(CASE WHEN equipment_rentals.due_date IS NOT NULL AND equipment_rentals.due_date < ? THEN 1 END) as overdue_count',
                [CarbonImmutable::now()->startOfDay()->toDateString()],
            )
            ->first();

        return [
            [
                'key' => 'stock_value',
                'value' => round((float) ($stock->value ?? 0), 2),
                'format' => 'money',
                'meta' => ['units' => (int) ($stock->units ?? 0)],
            ],
            [
                'key' => 'on_loan',
                'value' => (int) ($rentals->open_count ?? 0),
                'format' => 'number',
                'meta' => null,
            ],
            [
                'key' => 'overdue',
                'value' => (int) ($rentals->overdue_count ?? 0),
                'format' => 'number',
                'meta' => null,
            ],
            [
                'key' => 'low_stock',
                'value' => count($this->lowStock($filters)),
                'format' => 'number',
                'meta' => null,
            ],
        ];
    }

    /**
     * Each subscription's stages, from enrolled to settled.
     *
     * Exempt lines are their own stage rather than being counted as paid or
     * unpaid — the club decided they owe nothing, which is a different fact
     * from having paid.
     */
    private function subscriptionFunnel(DashboardFilters $filters): array
    {
        $query = DB::table('player_subscriptions')
            ->join('subscriptions', 'subscriptions.id', '=', 'player_subscriptions.subscription_id')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->where('players.archived', false);

        if ($branchIds = BranchScope::playerIdsQuery($filters->branchId)) {
            $query->whereIn('player_subscriptions.player_id', $branchIds);
        }

        return $query
            ->groupBy('subscriptions.id', 'subscriptions.name', 'subscriptions.year')
            ->orderByDesc('subscriptions.year')
            ->orderBy('subscriptions.name')
            ->selectRaw(
                'subscriptions.name as name, subscriptions.year as year, '
                .'COUNT(*) as enrolled, '
                .'COUNT(CASE WHEN player_subscriptions.is_exempt = 1 THEN 1 END) as exempt, '
                .'COUNT(CASE WHEN player_subscriptions.is_exempt = 0 AND player_subscriptions.amount_paid >= player_subscriptions.amount_owed THEN 1 END) as paid, '
                .'COUNT(CASE WHEN player_subscriptions.is_exempt = 0 AND player_subscriptions.amount_paid > 0 AND player_subscriptions.amount_paid < player_subscriptions.amount_owed THEN 1 END) as partial, '
                .'COUNT(CASE WHEN player_subscriptions.is_exempt = 0 AND player_subscriptions.amount_paid <= 0 AND player_subscriptions.amount_owed > 0 THEN 1 END) as unpaid, '
                .'SUM(CASE WHEN player_subscriptions.is_exempt = 0 THEN player_subscriptions.amount_paid ELSE 0 END) as collected, '
                .'SUM(CASE WHEN player_subscriptions.is_exempt = 0 THEN player_subscriptions.amount_owed ELSE 0 END) as billed'
            )
            ->get()
            ->map(function (object $row): array {
                $billed = (float) $row->billed;

                return [
                    'name' => (string) $row->name,
                    'year' => (int) $row->year,
                    'enrolled' => (int) $row->enrolled,
                    'paid' => (int) $row->paid,
                    'partial' => (int) $row->partial,
                    'unpaid' => (int) $row->unpaid,
                    'exempt' => (int) $row->exempt,
                    'collected' => round((float) $row->collected, 2),
                    'billed' => round($billed, 2),
                    'rate' => $billed > 0 ? round((float) $row->collected / $billed * 100, 1) : null,
                ];
            })
            ->values()
            ->all();
    }

    private function itemsByStatus(DashboardFilters $filters): array
    {
        return $this->items($filters)
            ->groupBy('equipment_items.status')
            ->orderByDesc('total')
            ->selectRaw('equipment_items.status as status, SUM(equipment_items.quantity) as total')
            ->get()
            ->map(fn (object $row): array => [
                'status' => (string) $row->status,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Catalogs whose available quantity has fallen to the threshold.
     *
     * Available is stocked quantity minus what is out on loan. There is no
     * per-catalog reorder point in the schema, so one flat rule applies.
     */
    private function lowStock(DashboardFilters $filters): array
    {
        if ($this->lowStockCache !== null) {
            return $this->lowStockCache;
        }

        $outstanding = DB::table('equipment_rentals')
            ->whereNull('return_date')
            ->selectRaw('equipment_item_id, COALESCE(SUM(quantity - returned_quantity), 0) as out')
            ->groupBy('equipment_item_id');

        return $this->lowStockCache = $this->stockItems($filters)
            ->leftJoinSub($outstanding, 'r', 'r.equipment_item_id', '=', 'equipment_items.id')
            ->join('equipment_catalogs', 'equipment_catalogs.id', '=', 'equipment_items.catalog_id')
            ->groupBy('equipment_catalogs.id', 'equipment_catalogs.name')
            ->havingRaw('SUM(equipment_items.quantity - COALESCE(r.out, 0)) <= ?', [self::LOW_STOCK_THRESHOLD])
            ->orderByRaw('SUM(equipment_items.quantity - COALESCE(r.out, 0)) asc')
            ->limit(self::LOW_STOCK_ROWS)
            ->selectRaw('equipment_catalogs.name as name, SUM(equipment_items.quantity - COALESCE(r.out, 0)) as available')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'available' => (int) $row->available,
            ])
            ->values()
            ->all();
    }

    private function rentalsPerMonth(DashboardFilters $filters): array
    {
        $labels = MonthBucket::lastTwelve($filters->anchor());
        $bucket = MonthBucket::expression('equipment_rentals.checkout_date');

        $counts = $this->rentals($filters)
            ->selectRaw("{$bucket} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        return [
            'labels' => $labels,
            'counts' => MonthBucket::series($counts, $labels),
        ];
    }

    /**
     * When the kit was last counted.
     *
     * Not branch-scoped: an inventory session covers the club's stock as a
     * whole and has no branch of its own.
     */
    private function inventory(): array
    {
        $last = DB::table('inventory_sessions')
            ->where('status', 'completed')
            ->orderByDesc('session_date')
            ->first(['session_date', 'total_expected', 'total_found', 'total_missing']);

        return [
            'lastSession' => $last?->session_date ? CarbonImmutable::parse($last->session_date)->toDateString() : null,
            'expected' => (int) ($last->total_expected ?? 0),
            'found' => (int) ($last->total_found ?? 0),
            'missing' => (int) ($last->total_missing ?? 0),
            'inProgress' => (int) DB::table('inventory_sessions')->where('status', 'in_progress')->count(),
        ];
    }

    private function items(DashboardFilters $filters): Builder
    {
        $query = DB::table('equipment_items');

        if ($filters->branchId !== null) {
            $query->whereIn(
                'equipment_items.id',
                DB::table('branch_equipment_item')
                    ->where('branch_id', $filters->branchId)
                    ->select('equipment_item_id'),
            );
        }

        return $query;
    }

    /** Items that still count as usable stock. */
    private function stockItems(DashboardFilters $filters): Builder
    {
        return $this->items($filters)
            ->whereNotIn('equipment_items.status', self::NON_STOCK_STATUSES);
    }

    private function rentals(DashboardFilters $filters): Builder
    {
        $query = DB::table('equipment_rentals');

        if ($filters->branchId !== null) {
            $query->whereIn(
                'equipment_rentals.equipment_item_id',
                DB::table('branch_equipment_item')
                    ->where('branch_id', $filters->branchId)
                    ->select('equipment_item_id'),
            );
        }

        return $query;
    }

    private function openRentals(DashboardFilters $filters): Builder
    {
        return $this->rentals($filters)->whereNull('equipment_rentals.return_date');
    }
}
