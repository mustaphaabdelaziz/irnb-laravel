<?php

namespace App\Services\Secretariat;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\MissionOrderTraveler;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Competitions\TeamMembers;
use App\Support\CategoryLabels;
use App\Support\Season;
use App\Support\UiLang;

/**
 * Who can travel on a mission order, as the form offers them: users (coach by
 * default), current board members (their board role), players (player — their
 * category) and, through "Add a team", a team's coaches and players of the
 * current season. Names and functions are defaults only: the order keeps its
 * own editable copy.
 */
final class MissionTravelers
{
    /**
     * The form's pickers. Players need players → view; teams need teams →
     * view and players → view (a team brings its players). Null hides a picker.
     *
     * @return array{users: list<array<string, mixed>>, boardMembers: list<array<string, mixed>>, players: ?list<array<string, mixed>>, teams: ?list<array<string, mixed>>}
     */
    public function options(User $viewer): array
    {
        $seesPlayers = $viewer->hasPermission('players', 'view');

        return [
            'users' => User::query()->where('is_active', true)
                ->orderBy('lastname')->orderBy('firstname')->orderBy('name')
                ->get(['id', 'name', 'firstname', 'lastname'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->fullname, 'function' => self::coachFunction()])
                ->values()->all(),
            'boardMembers' => $this->boardMembers(),
            'players' => $seesPlayers ? $this->players() : null,
            'teams' => $seesPlayers && $viewer->hasPermission('teams', 'view') ? $this->teams() : null,
        ];
    }

    /**
     * "Add a team": its coaches, then its players (A–Z) of the current season,
     * as rows the form lists ticked. Players who left the team's sport stay on
     * the roster, marked, but do not travel: they are left out. Each person once.
     *
     * @return list<array{traveler_type: string, traveler_id: int, name: string, function: string}>
     */
    public function team(Team $team): array
    {
        $season = Season::current()->startYear;
        $members = TeamMembers::of($team);

        // Same people as the pickers: no inactive coach, no archived player.
        $coaches = $team->coachesFor($season)->where('users.is_active', true)->orderBy('users.lastname')->orderBy('users.firstname')->get()
            ->map(fn (User $user) => self::row(MissionOrderTraveler::USER, $user->id, $user->fullname, self::coachFunction()));
        $players = $team->rosterFor($season)->where('players.archived', false)->with(TeamMembers::RELATIONS)->get()
            ->reject(fn (Player $player) => $members->hasLeft($player))
            ->map(fn (Player $player) => self::row(
                MissionOrderTraveler::PLAYER, $player->id, $player->fullname,
                self::playerFunction($members->category($player)?->localized_name),
            ))
            ->sortBy('name');

        return $coaches->concat($players)
            ->unique(fn (array $row) => $row['traveler_type'].'|'.$row['traveler_id'])
            ->values()->all();
    }

    public static function coachFunction(): string
    {
        return UiLang::get('mis.function.coach');
    }

    /** «لاعب — U15», or «لاعب» alone when the player has no category. */
    public static function playerFunction(?string $category): string
    {
        return $category === null || $category === ''
            ? UiLang::get('player')
            : strtr(UiLang::get('mis.function.player'), ['{category}' => $category]);
    }

    /** @return list<array<string, mixed>> active members of the current term, their role as the function */
    private function boardMembers(): array
    {
        // A role's typed label first (custom roles), else its built-in translation — never the raw name.
        $labels = BoardRole::query()->whereNotNull('label')->where('label', '!=', '')->pluck('label', 'name');

        return BoardMember::query()->withPlayer()->inCurrentTerm()->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (BoardMember $member) use ($labels) {
                $role = (string) $member->role;
                $function = $role === '' ? '' : ($labels[$role] ?? UiLang::get($role, $role));

                return ['value' => $member->id, 'label' => $member->name, 'description' => $function, 'function' => $function];
            })
            ->values()->all();
    }

    /** @return list<array<string, mixed>> players still in the club (not archived, not left), A–Z */
    private function players(): array
    {
        return Player::query()->where('archived', false)->notLeft()
            ->with('category')
            ->orderBy('lastname')->orderBy('firstname')
            ->get(['id', 'category_id', 'membership_id', ...Player::NAME_COLUMNS])
            ->map(function (Player $player) {
                $category = $player->category?->localized_name;

                return [
                    'value' => $player->id,
                    'label' => $player->fullname,
                    'description' => implode(' · ', array_filter([$player->membership_id, $category])),
                    'function' => self::playerFunction($category),
                ];
            })
            ->values()->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function teams(): array
    {
        $labels = CategoryLabels::make();

        return Team::query()->where('archived', false)->orderBy('name')->get(['id', 'name', 'category_id'])
            ->map(fn (Team $team) => ['value' => $team->id, 'label' => $team->labelWith($labels)])
            ->values()->all();
    }

    /** @return array{traveler_type: string, traveler_id: int, name: string, function: string} */
    private static function row(string $type, int $id, string $name, string $function): array
    {
        return ['traveler_type' => $type, 'traveler_id' => $id, 'name' => $name, 'function' => $function];
    }
}
