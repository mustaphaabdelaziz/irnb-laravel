<?php

namespace Tests\Feature\Competitions;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerFlagsPerSportTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private Team $footTeam;

    private Team $swimTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footTeam = $this->team($this->senior, ['branch_id' => $this->foot->id]);
        $this->swimTeam = $this->team($this->u15, ['branch_id' => $this->swim->id]);
    }

    private function subscription(array $branchIds = []): Subscription
    {
        $subscription = Subscription::create([
            'name' => 'Fee '.uniqid(), 'year' => 2026, 'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);
        if ($branchIds !== []) {
            $subscription->branches()->attach($branchIds);
        }

        return $subscription;
    }

    private function charge(Player $player, ?Subscription $subscription, float $owed, float $paid = 0, array $extra = []): PlayerSubscription
    {
        return PlayerSubscription::create($extra + [
            'player_id' => $player->id, 'subscription_id' => $subscription?->id, 'year' => 2026,
            'status_at_time' => 'student', 'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => $paid,
        ]);
    }

    /** @return array<string, mixed> */
    private function flags(Player $player, Team $team, ?User $viewer = null): array
    {
        return app(PlayerFlags::class)->for([$player->id], $viewer ?? $this->admin(), team: $team)[$player->id];
    }

    #[Test]
    public function unpaid_counts_that_sports_debts_only(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);

        $this->assertTrue($this->flags($player, $this->footTeam)['unpaid']);
        $this->assertSame(1000.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertFalse($this->flags($player, $this->swimTeam)['unpaid']);
        $this->assertNull($this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function the_stored_total_is_not_used(): void
    {
        $player = $this->bothActive();
        DB::table('players')->where('id', $player->id)->update(['outstanding_debt' => 9000]);

        $this->assertFalse($this->flags($player, $this->footTeam)['unpaid']);
    }

    #[Test]
    public function the_amount_is_that_sports_alone(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $this->charge($player, $this->subscription([$this->swim->id]), 300);

        $this->assertSame(1000.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertSame(300.0, $this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function a_subscription_with_no_branch_counts_on_both_teams(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription(), 2000);

        $this->assertSame(2000.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertSame(2000.0, $this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function a_subscription_ticked_for_both_counts_on_both_teams(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id, $this->swim->id]), 2000);

        $this->assertTrue($this->flags($player, $this->footTeam)['unpaid']);
        $this->assertTrue($this->flags($player, $this->swimTeam)['unpaid']);
    }

    #[Test]
    public function a_charge_whose_subscription_was_deleted_still_counts(): void
    {
        $player = $this->bothActive();
        $subscription = $this->subscription([$this->foot->id]);
        $charge = $this->charge($player, $subscription, 700);
        $subscription->delete();
        DB::table('player_subscriptions')->where('id', $charge->id)->update(['subscription_id' => null]);

        $this->assertSame(700.0, $this->flags($player, $this->footTeam)['debt']);
        $this->assertSame(700.0, $this->flags($player, $this->swimTeam)['debt']);
    }

    #[Test]
    public function a_settled_discounted_or_exempt_charge_is_not_unpaid(): void
    {
        $player = $this->bothActive();
        $football = [$this->foot->id];
        $this->charge($player, $this->subscription($football), 500, 500);
        $this->charge($player, $this->subscription($football), 500, 0, ['discount_type' => 'percent', 'discount_value' => 100]);
        $this->charge($player, $this->subscription($football), 500, 0, ['is_exempt' => true]);

        $this->assertFalse($this->flags($player, $this->footTeam)['unpaid']);
    }

    #[Test]
    public function a_partly_paid_charge_shows_what_remains(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000, 400);

        $this->assertSame(600.0, $this->flags($player, $this->footTeam)['debt']);
    }

    #[Test]
    public function debt_stays_on_the_team_of_a_branch_they_left(): void
    {
        $player = $this->leftFootball();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);

        $this->assertTrue($this->flags($player, $this->footTeam)['unpaid']);
        $this->assertFalse($this->flags($player, $this->swimTeam)['unpaid']);
    }

    #[Test]
    public function left_both_still_shows_what_each_sport_is_owed(): void
    {
        $player = $this->leftBoth();
        $this->charge($player, $this->subscription([$this->swim->id]), 300);

        $this->assertSame(300.0, $this->flags($player, $this->swimTeam)['debt']);
        $this->assertFalse($this->flags($player, $this->footTeam)['unpaid']);
    }

    #[Test]
    public function no_registration_uses_own_licence_and_counts_charges_by_the_same_rule(): void
    {
        $flat = $this->noRegistration();
        $flat->update(['licence_number' => 'OWN-1']);
        $this->charge($flat, $this->subscription([$this->foot->id]), 800);

        $this->assertFalse($this->flags($flat, $this->footTeam)['no_licence']);
        $this->assertTrue($this->flags($flat, $this->footTeam)['unpaid']);
        $this->assertFalse($this->flags($flat, $this->swimTeam)['unpaid']);
    }

    #[Test]
    public function the_licence_is_the_one_in_the_teams_branch(): void
    {
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => 'FB-1']);
        $player->registrations()->where('branch_id', $this->swim->id)->update(['licence_number' => null]);
        app(RegistrationService::class)->refreshMirror($player);

        $this->assertFalse($this->flags($player, $this->footTeam)['no_licence']);
        $this->assertTrue($this->flags($player, $this->swimTeam)['no_licence']);
    }

    #[Test]
    public function a_blank_licence_counts_as_none(): void
    {
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => '   ']);

        $this->assertTrue($this->flags($player, $this->footTeam)['no_licence']);
    }

    #[Test]
    public function the_team_comes_from_the_game_or_the_competition(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $flags = app(PlayerFlags::class);
        $admin = $this->admin();
        $swimGame = $this->game($this->swimTeam);
        $footCompetition = $this->competition($this->footTeam, 2026);

        $this->assertFalse($flags->for([$player->id], $admin, $swimGame)[$player->id]['unpaid']);
        $this->assertTrue($flags->for([$player->id], $admin, null, $footCompetition)[$player->id]['unpaid']);
    }

    #[Test]
    public function with_no_team_every_charge_and_the_main_licence_count(): void
    {
        $player = $this->bothActive();
        $player->registrations()->where('branch_id', $this->foot->id)->update(['licence_number' => 'FB-1']);
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $this->charge($player, $this->subscription([$this->swim->id]), 300);

        $flags = app(PlayerFlags::class)->for([$player->id], $this->admin())[$player->id];

        $this->assertSame(1300.0, $flags['debt']);
        $this->assertFalse($flags['no_licence']);
    }

    #[Test]
    public function the_amount_stays_hidden_without_finance_view(): void
    {
        $player = $this->bothActive();
        $this->charge($player, $this->subscription([$this->foot->id]), 1000);
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['games' => ['view']]])->id]);

        $flags = $this->flags($player, $this->footTeam, $viewer);

        $this->assertTrue($flags['unpaid']);
        $this->assertNull($flags['debt']);
    }

    #[Test]
    public function badges_cost_no_query_per_player(): void
    {
        $count = function (int $players): int {
            $ids = [];
            foreach (range(1, $players) as $n) {
                $player = $this->bothActive("Q$players$n");
                $this->charge($player, $this->subscription([$this->foot->id]), 100);
                $ids[] = $player->id;
            }
            $admin = $this->admin();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PlayerFlags::class)->for($ids, $admin, team: $this->footTeam);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($count(1), $count(5));
    }
}
