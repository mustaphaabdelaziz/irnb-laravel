<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Player\RegistrationService;

/**
 * The spec's four cases: a football Senior and swimming U15 player, both active;
 * left football; left both; no registration at all. Football is the main branch.
 */
trait RegistrationFixtures
{
    private Branch $foot;

    private Branch $swim;

    private Category $senior;

    private Category $u15;

    private string $leaveDate = '2026-03-15';

    private int $fixturePlayers = 0;

    private function twoSportClub(): void
    {
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->senior = Category::create(['name' => 'Senior', 'branch_id' => $this->foot->id]);
        $this->u15 = Category::create(['name' => 'U15', 'branch_id' => $this->swim->id]);
    }

    private function bothActive(string $name = 'Both'): Player
    {
        $player = Player::create(['membership_id' => $this->fixtureId(), 'firstname' => $name, 'lastname' => 'Test', 'join_year' => 2020]);
        $service = app(RegistrationService::class);
        $service->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $service->register($player, ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]);

        return $player->refresh();
    }

    private function leftFootball(string $name = 'LeftFoot'): Player
    {
        $player = $this->bothActive($name);
        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        return $player->refresh();
    }

    private function leftBoth(string $name = 'LeftBoth'): Player
    {
        $player = $this->bothActive($name);
        app(RegistrationService::class)->leave($player, [$this->foot->id, $this->swim->id], $this->leaveDate);

        return $player->refresh();
    }

    /** Values only on players, as in a club with no branches. */
    private function noRegistration(string $name = 'Flat', ?int $categoryId = null): Player
    {
        return Player::create(['membership_id' => $this->fixtureId(), 'firstname' => $name, 'lastname' => 'Test', 'join_year' => 2020, 'category_id' => $categoryId ?? $this->senior->id]);
    }

    /** players.membership_id is required and unique, so every fixture player gets a distinct one. */
    private function fixtureId(): string
    {
        return 'FX-'.str_pad((string) ++$this->fixturePlayers, 5, '0', STR_PAD_LEFT);
    }
}
