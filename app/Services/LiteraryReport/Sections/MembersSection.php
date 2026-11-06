<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Membership at the end of the period (owner rule 2026-11-01): not archived,
 * joined by that year (or no join year), and not left by that day. A player
 * in several branches counts in each branch row, with that branch's category, once in the totals. A player
 * with an empty or unknown gender is counted with the boys (the DB default is 'Male').
 */
final class MembersSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $end = $to->toDateString();
        $activeQuery = Player::query()
            ->where('archived', false)
            ->where(fn (Builder $q) => $q->whereNull('join_year')->orWhere('join_year', '<=', $to->year))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereNull('left_at')->notLeft())
                ->orWhereDate('left_at', '>', $end));

        $active = (clone $activeQuery)->get(['id', 'category_id', 'gender']);
        // The registrations still active at the end of that day, each with its own category.
        $links = BranchRegistration::query()
            ->activeOn($to->addDay()->toDateString())
            ->whereIn('player_id', (clone $activeQuery)->select('id'))
            ->get(['player_id', 'branch_id', 'category_id'])
            ->groupBy('player_id');

        $categories = Category::query()->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])->keyBy('id');
        $branches = Branch::query()->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])->keyBy('id');

        $rows = [];
        $female = 0;
        foreach ($active as $player) {
            $isFemale = strtolower((string) $player->gender) === 'female';
            $female += $isFemale ? 1 : 0;
            // No registration: the "no branch" bucket, on the player's own category.
            $memberships = $links->get($player->id)?->map(fn (BranchRegistration $r) => [$r->branch_id, $r->category_id])->all()
                ?? [[null, $player->category_id]];
            foreach ($memberships as [$branchId, $categoryId]) {
                $key = ($branchId ?? 0).'|'.($categoryId ?? 0);
                $rows[$key] ??= [
                    'branch' => $branchId ? $branches->get($branchId)?->localized_name : null,
                    'category' => $categoryId ? $categories->get($categoryId)?->localized_name : null,
                    'male' => 0, 'female' => 0, 'total' => 0,
                ];
                $rows[$key][$isFemale ? 'female' : 'male']++;
                $rows[$key]['total']++;
            }
        }

        // Branch rows first (A–Z), the "no branch" bucket last; categories A–Z inside.
        usort($rows, fn (array $a, array $b) => [$a['branch'] === null, (string) $a['branch'], (string) $a['category']]
            <=> [$b['branch'] === null, (string) $b['branch'], (string) $b['category']]);

        return [
            'total' => $active->count(),
            'male' => $active->count() - $female,
            'female' => $female,
            'rows' => $rows,
            'multi_branch' => $links->contains(fn ($rows) => $rows->count() > 1),
            'joined' => Player::query()->whereBetween('join_year', [$from->year, $to->year])->count(),
            'left' => Player::query()->whereDate('left_at', '>=', $from->toDateString())->whereDate('left_at', '<=', $end)->count(),
            'coaches' => DB::table('team_coaches')
                ->join('teams', 'teams.id', '=', 'team_coaches.team_id')
                ->join('users', 'users.id', '=', 'team_coaches.user_id')
                ->whereBetween('team_coaches.season_start_year', [Season::forDate($from)->startYear, Season::forDate($to)->startYear])
                ->distinct()
                ->orderBy('users.name')->orderBy('teams.name')
                ->get(['users.name as name', 'teams.name as team', 'team_coaches.role as role'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }
}
