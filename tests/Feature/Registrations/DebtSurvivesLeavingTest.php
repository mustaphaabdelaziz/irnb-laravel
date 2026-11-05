<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\Subscription;
use App\Services\Dashboard\Support\BranchScope;
use App\Services\Finance\PruneOutOfBranchObligations;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: leaving never cancels money. */
class DebtSurvivesLeavingTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function footballSub(): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Football', 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach($this->foot->id);

        return $sub;
    }

    #[Test]
    public function leaving_touches_no_charge(): void
    {
        $player = $this->bothActive();
        $owed = $this->footballSub()->assignTo($player);
        app(RecalculatePlayerDebtService::class)->forPlayer($player);
        $before = DB::table('player_subscriptions')->where('id', $owed->id)->first();
        $debt = (float) $player->fresh()->outstanding_debt;

        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->assertEquals($before, DB::table('player_subscriptions')->where('id', $owed->id)->first());
        $this->assertSame($debt, (float) $player->fresh()->outstanding_debt);
        $this->assertSame(1500.0, $debt);
    }

    #[Test]
    public function leaving_every_branch_still_owes(): void
    {
        $player = $this->bothActive();
        $owed = $this->footballSub()->assignTo($player);

        $before = (array) DB::table('player_subscriptions')->find($owed->id);

        app(RegistrationService::class)->leave($player, [$this->foot->id, $this->swim->id], $this->leaveDate);
        app(RecalculatePlayerDebtService::class)->forPlayer($player);

        $this->assertEquals($before, (array) DB::table('player_subscriptions')->find($owed->id));
        $this->assertSame(1500.0, (float) $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function pruning_counts_a_left_registration_as_in_the_branch(): void
    {
        $sub = $this->footballSub();
        $owed = $sub->assignTo($this->leftFootball());
        $outsider = Player::create(['firstname' => 'Outsider', 'join_year' => 2020, 'membership_id' => $this->fixtureId()]);
        app(RegistrationService::class)->register($outsider, ['branch_id' => $this->swim->id]);
        $stale = $sub->assignTo($outsider);

        $removed = app(PruneOutOfBranchObligations::class)->forSubscription($sub);

        $this->assertSame(1, $removed);
        $this->assertModelExists($owed);
        $this->assertModelMissing($stale);
    }

    #[Test]
    public function the_branch_scope_keeps_a_left_registration_for_its_debt(): void
    {
        $left = $this->leftFootball();

        $this->assertSame([$left->id], BranchScope::players(Player::query(), $this->foot->id)->pluck('id')->all());
        $this->assertSame([$left->id], BranchScope::playerIdsQuery($this->foot->id)->pluck('player_id')->all());
    }
}
