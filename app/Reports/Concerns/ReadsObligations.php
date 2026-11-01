<?php

namespace App\Reports\Concerns;

use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Reports\Filter;
use App\Reports\ReportFilters;
use App\Support\UiLang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What players owe through subscriptions, read as plain rows and priced by
 * PlayerSubscription::moneyFor() — the one rule for discount, net owed,
 * remaining and status, so a report never disagrees with the player page.
 * Archived players are left out, as on the players strip's total debt.
 */
trait ReadsObligations
{
    use FiltersPlayers;

    /** @return list<Filter> season, kind, subscription, category, branch */
    protected function obligationFilters(): array
    {
        $years = Subscription::query()->whereNotNull('year')->distinct()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y);

        return [
            Filter::multi('season', 'reports.filter.season', $years
                ->map(fn (int $y) => ['value' => $y, 'label' => Subscription::seasonLabel($y)])->all()),
            Filter::select('kind', 'subscription_kind', array_map(
                fn (string $k) => ['value' => $k, 'label' => UiLang::get('subscription_kind_'.$k)], Subscription::KINDS)),
            Filter::multi('subscription_id', 'reports.filter.subscription', Subscription::query()->orderByDesc('year')->orderBy('name')->get(['id', 'name', 'year'])
                ->map(fn (Subscription $s) => ['value' => $s->id, 'label' => $s->name.($s->year ? ' ('.Subscription::seasonLabel((int) $s->year).')' : '')])->all()),
            $this->categoryFilter(),
            $this->branchFilter(),
        ];
    }

    /**
     * Every matching obligation with its money figures.
     *
     * @return Collection<int, object>
     */
    protected function obligations(ReportFilters $filters): Collection
    {
        $query = DB::table('player_subscriptions as ps')
            ->join('players', 'players.id', '=', 'ps.player_id')
            ->join('subscriptions as s', 's.id', '=', 'ps.subscription_id')
            ->where('players.archived', false);

        if ($filters->filled('season')) {
            $query->whereIn('s.year', $filters->get('season'));
        }
        if ($filters->filled('kind')) {
            $query->where('s.kind', $filters->get('kind'));
        }
        if ($filters->filled('subscription_id')) {
            $query->whereIn('s.id', $filters->get('subscription_id'));
        }
        if ($filters->filled('category_id')) {
            $query->whereIn('players.category_id', $filters->get('category_id'));
        }
        if ($filters->filled('branch_id')) {
            $query->whereIn('players.id', DB::table('branch_player')->whereIn('branch_id', $filters->get('branch_id'))->select('player_id'));
        }

        return $query->get([
            'ps.id', 'ps.player_id', 'ps.label', 'ps.amount_owed', 'ps.amount_paid', 'ps.is_exempt', 'ps.discount_type', 'ps.discount_value', 'ps.due_date',
            's.id as subscription_id', 's.name as subscription_name', 's.year as subscription_year', 's.kind as subscription_kind',
            'players.membership_id', 'players.category_id', 'players.phones',
            'players.firstname', 'players.lastname', 'players.nickname', 'players.father', 'players.grandfather', 'players.gender',
        ])->map(function (object $row) {
            $row->money = PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, (bool) $row->is_exempt, $row->discount_type, $row->discount_value);

            return $row;
        });
    }
}
