<?php

namespace Tests\Support;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\Opponent;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;

/** Builders for the competitions module; admins, categories and players come from AttendanceFixtures. */
trait CompetitionFixtures
{
    use AttendanceFixtures;

    private function sportBranch(string $name = 'Football'): Branch
    {
        return Branch::firstOrCreate(['name' => $name]);
    }

    private function format(?Branch $branch = null, array $extra = []): GameFormat
    {
        return GameFormat::create($extra + [
            'branch_id' => ($branch ?? $this->sportBranch())->id,
            'sport' => 'football', 'name' => 'Test 11',
            'players_on_field' => 11, 'max_bench' => 9, 'max_called_up' => 20,
            'periods_count' => 2, 'period_minutes' => 45, 'period_label' => 'half',
            'scoring' => 'goals', 'sanction_types' => [],
        ]);
    }

    private function team(?Category $category = null, array $extra = []): Team
    {
        $branch = isset($extra['branch_id']) ? Branch::findOrFail($extra['branch_id']) : $this->sportBranch();

        return Team::create($extra + [
            'branch_id' => $branch->id,
            'category_id' => ($category ?? Category::firstOrCreate(['name' => 'U15']))->id,
            'name' => 'Team '.uniqid(),
            'default_game_format_id' => $this->format($branch)->id,
        ]);
    }

    /** Puts $players on the team's roster for $season with shirts 1, 2, 3… */
    private function onRoster(Team $team, int $season, array $players): void
    {
        foreach (array_values($players) as $i => $player) {
            $team->players()->attach($player->id, ['season_start_year' => $season, 'shirt_number' => $i + 1]);
        }
    }

    private function opponent(string $name = 'Rivals FC'): Opponent
    {
        return Opponent::firstOrCreate(['name' => $name]);
    }

    private function competition(Team $team, int $season, array $extra = []): Competition
    {
        return Competition::create($extra + [
            'team_id' => $team->id, 'season_start_year' => $season, 'name' => 'League', 'type' => 'league',
        ]);
    }

    private function game(Team $team, array $extra = []): Game
    {
        return Game::create($extra + [
            'team_id' => $team->id, 'season_start_year' => 2026,
            'game_format_id' => $team->default_game_format_id,
            'opponent_id' => $this->opponent()->id,
            'side' => GameSide::Home, 'date' => '2026-10-17', 'status' => GameStatus::Scheduled,
        ]);
    }

    /** A user whose role limits them to the teams they coach. */
    private function coach(array $permissions = []): User
    {
        $role = Role::factory()->create([
            'own_teams_only' => true,
            'permissions' => $permissions + [
                'teams' => ['view', 'edit'],
                'competitions' => ['view', 'add', 'edit'],
                'games' => ['view', 'add', 'edit'],
            ],
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function assignCoach(Team $team, User $user, int $season, string $role = 'head'): void
    {
        $team->coaches()->attach($user->id, ['season_start_year' => $season, 'role' => $role]);
    }
}
