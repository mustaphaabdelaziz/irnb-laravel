<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Registrations with no exact join date, for an admin to complete. */
class JoinDateController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
        ]);

        $base = BranchRegistration::query()->needsJoinDate()
            ->whereHas('player', fn ($q) => $q->where('archived', false));

        $query = (clone $base)
            ->select('branch_player.*')
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('legacy_join_year', $year))
            ->with([
                'player:id,created_at,birthdate,'.implode(',', Player::NAME_COLUMNS),
                'branch:id,name,name_ar,name_fr,name_en',
                'category:id,name,name_ar,name_fr,name_en',
            ])
            ->addSelect(['two_branches' => DB::table('branch_player as other')
                ->selectRaw('COUNT(*) > 1')
                ->whereColumn('other.player_id', 'branch_player.player_id')])
            // One year shared by two sports is the least certain, so it goes first.
            ->orderByDesc('two_branches')
            ->orderBy('player_id')
            ->orderBy('branch_id');

        $registrations = $query->paginate(50)->withQueryString();
        $mainBranchId = Branch::mainId();

        // Saving the last row of the last page leaves that page empty; show the new last page instead.
        if ($registrations->currentPage() > $registrations->lastPage() && $registrations->lastPage() >= 1) {
            return redirect()->route('players.join-dates.index', [...$filters, 'page' => $registrations->lastPage()]);
        }

        return Inertia::render('Players/JoinDates', [
            'total' => (clone $base)->count(),
            'filters' => $filters,
            'branches' => Branch::orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'branch_id']),
            'years' => (clone $base)->whereNotNull('legacy_join_year')->distinct()->orderByDesc('legacy_join_year')->pluck('legacy_join_year'),
            'registrations' => $registrations
                ->through(fn (BranchRegistration $r) => [
                    'id' => $r->id,
                    'player_id' => $r->player_id,
                    'player_name' => $r->player->fullname,
                    'branch' => $r->branch->localized_name,
                    'category' => $r->category?->localized_name,
                    'membership_id' => $r->membership_id,
                    'year' => $r->legacy_join_year,
                    'two_branches' => (bool) $r->two_branches,
                    'hint' => $this->hint($r),
                    'suggestion' => $this->suggestion($r, $mainBranchId),
                ]),
        ]);
    }

    public function update(Request $request, BranchRegistration $registration, RegistrationService $registrations): RedirectResponse
    {
        $data = $request->validate([
            'join_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ]);

        $birthdate = $registration->player->birthdate?->toDateString();
        if ($birthdate !== null && $data['join_date'] < $birthdate) {
            throw ValidationException::withMessages(['join_date' => __('validation.join_date_before_birth')]);
        }

        $registrations->setJoinDate($registration, $data['join_date'], $request->user());

        return back()->with('success', 'flash.join_date_saved');
    }

    /**
     * Saves every date filled on the page at once. Good dates are kept even when
     * others are refused; the refused ones come back keyed by registration ID.
     */
    public function bulkUpdate(Request $request, RegistrationService $registrations): RedirectResponse
    {
        $data = $request->validate([
            'dates' => ['required', 'array', 'max:200'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ]);

        $rows = BranchRegistration::query()->needsJoinDate()
            ->whereIn('id', array_map('intval', array_keys($data['dates'])))
            ->with('player:id,birthdate')
            ->get();

        $saved = 0;
        $refused = [];
        foreach ($rows as $registration) {
            $date = $data['dates'][$registration->id];
            $birthdate = $registration->player->birthdate?->toDateString();
            if ($birthdate !== null && $date < $birthdate) {
                $refused['dates.'.$registration->id] = __('validation.join_date_before_birth');

                continue;
            }
            $registrations->setJoinDate($registration, $date, $request->user());
            $saved++;
        }

        return back()
            ->withErrors($refused)
            ->with('success', ['key' => 'flash.join_dates_saved', 'params' => ['count' => $saved]]);
    }

    /**
     * 1 September of the recorded year, the start of a season. Offered for the
     * main branch (football) and for a player's only branch; a second branch of a
     * two-branch player is dated by hand. Never before birth or after today.
     */
    private function suggestion(BranchRegistration $registration, ?int $mainBranchId): ?string
    {
        $year = $registration->legacy_join_year;
        if ($year === null) {
            return null;
        }
        if ($registration->two_branches && (int) $registration->branch_id !== $mainBranchId) {
            return null;
        }

        $date = sprintf('%04d-09-01', $year);
        $birthdate = $registration->player->birthdate?->toDateString();
        if ($date > now()->toDateString() || ($birthdate !== null && $date < $birthdate)) {
            return null;
        }

        return $date;
    }

    /** When the record was entered, if that year fits. A suggestion only: an admin must confirm it. */
    private function hint(BranchRegistration $registration): ?string
    {
        $entered = $registration->player->created_at;

        return $entered !== null && $entered->year === $registration->legacy_join_year
            ? $entered->toDateString()
            : null;
    }
}
