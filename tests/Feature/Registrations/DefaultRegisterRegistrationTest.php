<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: the caisse for a payment. */
class DefaultRegisterRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private FinanceAccount $footSenior;

    private FinanceAccount $swimU15;

    private int $subCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->footSenior = $this->register($this->foot, $this->senior);
        $this->swimU15 = $this->register($this->swim, $this->u15);
    }

    private function register(Branch $branch, Category $category): FinanceAccount
    {
        return FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse '.$branch->name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
    }

    private function clubCash(): FinanceAccount
    {
        return FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();
    }

    private function resolver(): DefaultRegisterResolver
    {
        return DefaultRegisterResolver::load();
    }

    private function sub(array $branches = [], array $categories = []): Subscription
    {
        $sub = Subscription::create([
            'name' => 'Season '.(++$this->subCount), 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));
        $sub->categories()->attach(collect($categories)->pluck('id'));

        return $sub;
    }

    #[Test]
    public function a_player_uses_the_main_registrations_caisse_whatever_the_row_order(): void
    {
        $player = Player::create(['firstname' => 'SwimFirst', 'join_year' => 2020, 'membership_id' => $this->fixtureId()]);
        $service = app(RegistrationService::class);
        $service->register($player, ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]);
        $service->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        $this->assertSame($this->footSenior->id, $this->resolver()->forPlayer($player->fresh())->id);
    }

    #[Test]
    public function a_caisse_chosen_on_the_form_replaces_the_preselected_one(): void
    {
        $player = $this->leftFootball();
        $owed = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $this->sub([$this->foot])->id,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => false, 'amount_owed' => 1500, 'amount_paid' => 0,
        ]);

        $this->actingAs(User::factory()->admin()->create())->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $owed->id, 'category' => 'subscription', 'amount' => 1500,
            'payment_method' => 'cash', 'finance_account_id' => $this->swimU15->id,
        ])->assertRedirect();

        $this->assertSame($this->swimU15->id, Transaction::query()->sole()->finance_account_id);
    }

    #[Test]
    public function a_left_main_branch_is_never_used(): void
    {
        $this->assertSame($this->swimU15->id, $this->resolver()->forPlayer($this->leftFootball())->id);
    }

    #[Test]
    public function left_everywhere_goes_to_club_cash(): void
    {
        $this->assertSame($this->clubCash()->id, $this->resolver()->forPlayer($this->leftBoth())->id);
    }

    #[Test]
    public function no_registration_goes_to_club_cash(): void
    {
        $this->assertSame($this->clubCash()->id, $this->resolver()->forPlayer($this->noRegistration())->id);
    }

    #[Test]
    public function a_one_branch_subscription_uses_that_branch_with_the_players_category_there(): void
    {
        $player = $this->bothActive();

        $this->assertSame($this->swimU15->id, $this->resolver()->forSubscription($this->sub([$this->swim]), $player)->id);
        $this->assertSame($this->swimU15->id, $this->resolver()->forSubscription($this->sub([$this->swim], [$this->u15, $this->senior]), $player)->id);
    }

    #[Test]
    public function a_one_branch_one_category_subscription_uses_its_own_category(): void
    {
        $this->assertSame($this->footSenior->id, $this->resolver()->forSubscription($this->sub([$this->foot], [$this->senior]), $this->leftBoth())->id);
    }

    #[Test]
    public function with_no_category_caisse_it_uses_that_branchs_treasury(): void
    {
        $this->swimU15->update(['is_active' => false]);

        $this->assertSame($this->swim->treasury->id, $this->resolver()->forSubscription($this->sub([$this->swim]), $this->bothActive())->id);
    }

    #[Test]
    public function no_branch_or_two_branches_leave_it_to_the_player(): void
    {
        $player = $this->bothActive();

        $this->assertNull($this->resolver()->forSubscription($this->sub(), $player));
        $this->assertNull($this->resolver()->forSubscription($this->sub([$this->foot, $this->swim]), $player));
    }

    #[Test]
    public function a_debt_of_a_left_branch_is_paid_into_that_branch(): void
    {
        $player = $this->leftFootball();
        $owed = $this->sub([$this->foot])->assignTo($player);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('players.transactions.store', $player), [
                'player_subscription_id' => $owed->id, 'category' => 'subscription', 'amount' => 100, 'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->footSenior->id, Transaction::latest('id')->value('finance_account_id'));
    }

    #[Test]
    public function a_donation_uses_the_main_registrations_caisse(): void
    {
        $player = $this->leftFootball();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('players.transactions.store', $player), ['category' => 'donation', 'amount' => 50, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->swimU15->id, Transaction::latest('id')->value('finance_account_id'));
    }

    #[Test]
    public function a_transaction_saved_with_no_account_follows_the_same_rule(): void
    {
        $player = $this->bothActive();
        $owed = $this->sub([$this->swim])->assignTo($player);

        $transaction = Transaction::create([
            'amount' => 100, 'transaction_date' => now(), 'transaction_type' => 'income', 'category' => 'subscription',
            'payment_method' => 'cash', 'status' => 'Paid',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'player_subscription_id' => $owed->id,
        ]);

        $this->assertSame($this->swimU15->id, $transaction->finance_account_id);
    }

    #[Test]
    public function the_pages_preselect_the_same_caisse(): void
    {
        $player = $this->leftFootball();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('players.show', $player))
            ->assertInertia(fn (Assert $page) => $page->where('defaultFinanceAccountId', $this->swimU15->id));

        $this->get(route('transactions.create'))
            ->assertInertia(fn (Assert $page) => $page->where('players.0.default_finance_account_id', $this->swimU15->id));
    }
}
