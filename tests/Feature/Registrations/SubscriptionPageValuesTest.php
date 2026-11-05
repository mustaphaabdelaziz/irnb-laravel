<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class SubscriptionPageValuesTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
    }

    private int $subCount = 0;

    private function sub(array $branches = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season'.(++$this->subCount > 1 ? ' '.$this->subCount : ''), 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));

        return $sub;
    }

    private function props(Subscription $sub): array
    {
        $props = [];
        $this->get(route('subscriptions.show', $sub))
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** bothActive() leaves the second registration without its own ID, so give swimming one. */
    private function swimmingIdOf(Player $player): string
    {
        $registration = $player->registrationIn($this->swim->id);
        $registration->update(['membership_id' => 'SW-2020-00001']);

        return $registration->membership_id;
    }

    #[Test]
    public function a_one_branch_subscription_shows_that_registrations_values(): void
    {
        $player = $this->bothActive();
        $swimId = $this->swimmingIdOf($player);
        $sub = $this->sub([$this->swim]);

        $offered = $this->props($sub)['availablePlayers'][0];
        $this->assertSame($swimId, $offered['membership_id']);
        $this->assertSame('U15', $offered['category']['name']);
        $this->assertArrayNotHasKey('registrations', $offered);

        $sub->assignTo($player);
        $row = $this->props($sub)['playerSubscriptions'][0]['player'];
        $this->assertSame($swimId, $row['membership_id']);
        $this->assertSame('U15', $row['category']['name']);
    }

    #[Test]
    public function a_registration_without_an_id_shows_none_not_the_players_other_id(): void
    {
        $player = $this->bothActive();
        $this->assertNull($player->registrationIn($this->swim->id)->membership_id);
        $sub = $this->sub([$this->swim]);

        $offered = $this->props($sub)['availablePlayers'][0];
        $this->assertNull($offered['membership_id']);

        $sub->assignTo($player);
        $row = $this->props($sub)['playerSubscriptions'][0]['player'];
        $this->assertNull($row['membership_id']);
    }

    #[Test]
    public function no_branch_or_two_branches_show_the_main_registration(): void
    {
        $player = $this->bothActive();
        $footId = $player->registrationIn($this->foot->id)->membership_id;

        foreach ([[], [$this->foot, $this->swim]] as $branches) {
            $sub = $this->sub($branches);
            $sub->assignTo($player);
            $row = $this->props($sub)['playerSubscriptions'][0]['player'];
            $this->assertSame($footId, $row['membership_id']);
            $this->assertSame('Senior', $row['category']['name']);
        }
    }

    #[Test]
    public function a_debt_of_a_left_branch_keeps_that_registrations_values(): void
    {
        $player = $this->leftFootball();
        $footId = $player->registrationIn($this->foot->id)->membership_id;
        $sub = $this->sub([$this->foot]);
        $sub->assignTo($player);

        $row = $this->props($sub)['playerSubscriptions'][0]['player'];

        $this->assertSame($footId, $row['membership_id']);
        $this->assertSame('Senior', $row['category']['name']);
    }

    #[Test]
    public function no_registration_shows_the_players_own_values(): void
    {
        $player = $this->noRegistration();
        $player->update(['membership_id' => '2020FLAT1']);
        $sub = $this->sub();
        $sub->assignTo($player);

        $row = $this->props($sub)['playerSubscriptions'][0]['player'];

        $this->assertSame('2020FLAT1', $row['membership_id']);
        $this->assertSame('Senior', $row['category']['name']);
    }

    #[Test]
    public function the_export_follows_the_page(): void
    {
        $player = $this->bothActive();
        $swimId = $this->swimmingIdOf($player);
        $sub = $this->sub([$this->swim]);
        $sub->assignTo($player);

        $csv = $this->get(route('subscriptions.export', ['subscription' => $sub, 'format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString($swimId, $csv);
        $this->assertStringContainsString('U15', $csv);
        $this->assertStringNotContainsString('Senior', $csv);
    }
}
