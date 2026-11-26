<?php

namespace App\Http\Controllers;

use App\Http\Requests\Mission\SaveMissionOrderRequest;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Team;
use App\Services\Secretariat\MissionOrders;
use App\Services\Secretariat\MissionTravelers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Secretariat → Mission orders (أوامر المهمة): list, form, cancel, the Print
 * buttons' reference call and "Add a team". MissionOrders owns every write
 * and its transaction; nothing here wraps one.
 */
class MissionOrderController extends Controller
{
    public function __construct(
        private readonly MissionOrders $missions,
        private readonly MissionTravelers $travelers,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'status' => ['nullable', Rule::in(MissionOrder::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();

        $orders = MissionOrder::query()
            ->with('travelers:id,mission_order_id,name,sort_order')
            // A date range, not YEAR(): portable, and it also matches dates stored with a time.
            ->when($filters['year'] ?? null, fn ($q, $year) => $q
                ->where('order_date', '>=', sprintf('%04d-01-01', $year))
                ->where('order_date', '<', sprintf('%04d-01-01', $year + 1)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text) {
                $like = '%'.$text.'%';
                $q->where('destination', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like)
                    ->orWhereHas('travelers', fn ($t) => $t->where('name', 'like', $like));
                // "2026/3" finds the order printed under mail entry 3 of 2026 (active or cancelled with it).
                if (preg_match('#(\d{4})\s*/\s*0*(\d+)\s*$#u', $text, $m)) {
                    $q->orWhereIn('mission_orders.id', MailEntry::query()->select('source_id')
                        ->where('source_type', MailEntry::SOURCE_MISSION)->where('direction', MailEntry::OUT)
                        ->where('year', (int) $m[1])->where('number', (int) $m[2]));
                }
            }))
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        $references = $this->missions->references($orders->getCollection());
        $orders->through(fn (MissionOrder $order) => [
            'id' => $order->id,
            'reference' => $references[$order->id] ?? null,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'destination' => $order->destination,
            'travelers' => $order->travelers->pluck('name')->take(3)->implode(', '),
            'travelers_count' => $order->travelers->count(),
            'departure_date' => $order->departure_date?->format('Y-m-d'),
            'return_date' => $order->return_date?->format('Y-m-d'),
            'status' => $order->status,
            'is_legacy' => $order->is_legacy,
        ]);

        return Inertia::render('Missions/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'years' => MissionOrder::query()->whereNotNull('order_date')->toBase()->pluck('order_date')
                ->map(fn ($date) => (int) substr((string) $date, 0, 4))
                ->unique()->sortDesc()->values(),
            'can' => [
                'create' => $user->hasPermission('missions', 'add'),
                'register' => $user->hasPermission('missions', 'edit'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Missions/Create', $this->travelers->options($request->user()));
    }

    public function store(SaveMissionOrderRequest $request): RedirectResponse
    {
        $order = $this->missions->create($request->validated(), $request->user());

        return redirect()->route('missions.show', $order)->with('success', 'flash.mission_created');
    }

    public function show(Request $request, MissionOrder $missionOrder): Response
    {
        $user = $request->user();
        $editable = $missionOrder->isEditable();

        return Inertia::render('Missions/Show', [
            'order' => $missionOrder->load('travelers'),
            'reference' => $this->missions->reference($missionOrder),
            'can' => [
                'edit' => $editable && $user->hasPermission('missions', 'edit'),
                'cancel' => $editable && $user->hasPermission('missions', 'delete'),
                // The first print takes the reference; cancelled and imported orders print without one.
                'register' => $editable && $user->hasPermission('missions', 'edit'),
            ],
        ]);
    }

    public function edit(Request $request, MissionOrder $missionOrder): Response|RedirectResponse
    {
        if (! $missionOrder->isEditable()) {
            return redirect()->route('missions.show', $missionOrder)->with('error', 'flash.mission_locked');
        }

        return Inertia::render('Missions/Edit', [
            'order' => $missionOrder->load('travelers'),
            ...$this->travelers->options($request->user()),
        ]);
    }

    public function update(SaveMissionOrderRequest $request, MissionOrder $missionOrder): RedirectResponse
    {
        $this->missions->update($missionOrder, $request->validated(), $request->user());

        return redirect()->route('missions.show', $missionOrder)->with('success', 'flash.mission_updated');
    }

    public function cancel(Request $request, MissionOrder $missionOrder): RedirectResponse
    {
        $this->missions->cancel($missionOrder, $request->user());

        return back()->with('success', 'flash.mission_cancelled');
    }

    /** The Print buttons: take (or get back) the order's reference, then the page opens the PDF. */
    public function register(Request $request, MissionOrder $missionOrder): JsonResponse
    {
        $entry = $this->missions->register($missionOrder, $request->user());

        return response()->json(['reference' => $entry->reference, 'created' => $entry->wasRecentlyCreated]);
    }

    /** "Add a team": the form writes orders (add or edit) and the team's page and players are readable. */
    public function teamMembers(Request $request, Team $team): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            ($user->hasPermission('missions', 'add') || $user->hasPermission('missions', 'edit'))
                && $user->hasPermission('teams', 'view') && $user->hasPermission('players', 'view'),
            403,
        );

        return response()->json(['travelers' => $this->travelers->team($team)]);
    }
}
