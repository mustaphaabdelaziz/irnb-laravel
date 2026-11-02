<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionCostLine;
use App\Models\SubscriptionTarification;
use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The costs a subscription's price must cover: saved with it, and private to Finance → view. */
class SubscriptionTarificationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** A user whose role grants every module except finance. */
    private function userWithoutFinance(): User
    {
        $permissions = collect(Role::MODULES)
            ->reject(fn (string $module): bool => $module === 'finance')
            ->mapWithKeys(fn (string $module): array => [$module => Role::ACTIONS])
            ->all();

        $role = Role::create(['key' => 'no_finance', 'name' => ['en' => 'No finance'], 'permissions' => $permissions]);

        return User::factory()->create([
            'privileges' => [],
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create([
            'name' => 'Annual', 'kind' => 'annual', 'year' => Subscription::currentSeasonEndYear(),
            'amount_student' => 15000, 'amount_worker' => 15000,
            'is_mandatory' => true, 'is_active' => true,
            ...$attributes,
        ]);
    }

    private function payload(array $over = []): array
    {
        return [
            'kind' => 'annual', 'name' => 'Annual', 'year' => Subscription::currentSeasonEndYear(),
            'amount_student' => 15000, 'amount_worker' => 15000, 'is_mandatory' => true,
            'category_ids' => [], 'category_prices' => [], 'branch_ids' => [],
            ...$over,
        ];
    }

    private function tarification(array $over = []): array
    {
        return [
            'sessions_per_week' => 3, 'weeks' => 40, 'expected_players' => 20, 'expected_payment_rate' => 85,
            'lines' => [
                ['label' => 'Head coach', 'unit' => 'per_session', 'amount' => 800, 'quantity' => 1],
                ['label' => 'Assistants', 'unit' => 'per_session', 'amount' => 700, 'quantity' => 2],
                ['label' => 'Bus', 'unit' => 'per_trip', 'amount' => 15000, 'quantity' => 10],
            ],
            ...$over,
        ];
    }

    #[Test]
    public function creating_a_subscription_saves_its_costs_in_order(): void
    {
        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification()]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $subscription = Subscription::firstOrFail();

        $this->assertSame(85, $subscription->tarification->expected_payment_rate);
        $this->assertSame(3, $subscription->tarification->sessions_per_week);
        $this->assertSame(['Head coach', 'Assistants', 'Bus'], $subscription->costLines->pluck('label')->all());
    }

    #[Test]
    public function updating_replaces_the_cost_lines(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification([
            'lines' => [['label' => 'Bus', 'unit' => 'per_trip', 'amount' => 9000, 'quantity' => 4]],
        ])]))->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame(1, $subscription->costLines()->count());
        $this->assertSame(1, SubscriptionTarification::where('subscription_id', $subscription->id)->count());
        $this->assertEquals(9000, $subscription->costLines->first()->amount);
    }

    #[Test]
    public function an_untouched_section_writes_no_rows(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), $this->payload(['tarification' => [
            'sessions_per_week' => null, 'weeks' => 40, 'expected_players' => null, 'expected_payment_rate' => 100, 'lines' => [],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame(0, SubscriptionTarification::count());
    }

    #[Test]
    public function blank_weeks_and_rate_take_the_defaults(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification([
            'weeks' => null, 'expected_payment_rate' => null,
        ])]))->assertSessionHasNoErrors();

        $saved = SubscriptionTarification::firstOrFail();
        $this->assertSame(40, $saved->weeks);
        $this->assertSame(100, $saved->expected_payment_rate);
    }

    #[Test]
    public function deleting_the_subscription_deletes_its_costs(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($admin)->delete(route('subscriptions.destroy', $subscription));

        $this->assertSame(0, SubscriptionTarification::count());
        $this->assertSame(0, SubscriptionCostLine::count());
    }

    #[Test]
    public function bad_values_are_refused(): void
    {
        $admin = $this->admin();
        $line = ['label' => 'Coach', 'unit' => 'per_trip', 'amount' => 800, 'quantity' => 1];

        foreach ([
            'tarification.lines.0.amount' => [['amount' => -1] + $line],
            'tarification.lines.0.label' => [['label' => ''] + $line],
            'tarification.lines.0.unit' => [['unit' => 'per_year'] + $line],
            'tarification.lines.0.quantity' => [['quantity' => 0] + $line],
        ] as $field => $lines) {
            $this->actingAs($admin)
                ->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification(['lines' => $lines])]))
                ->assertSessionHasErrors($field);
        }

        $this->actingAs($admin)
            ->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification(['expected_payment_rate' => 101])]))
            ->assertSessionHasErrors('tarification.expected_payment_rate');
    }

    #[Test]
    public function an_exceptional_subscription_refuses_per_session_costs(): void
    {
        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), $this->payload([
                'kind' => 'exceptional', 'year' => null,
                'tarification' => $this->tarification(['lines' => [
                    ['label' => 'Coach', 'unit' => 'per_session', 'amount' => 800, 'quantity' => 1],
                ]]),
            ]))
            ->assertSessionHasErrors('tarification.lines.0.unit');
    }

    #[Test]
    public function saving_without_finance_rights_leaves_the_costs_alone(): void
    {
        $subscription = $this->subscription();
        $this->actingAs($this->admin())->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($this->userWithoutFinance())
            ->put(route('subscriptions.update', $subscription), $this->payload(['amount_student' => 16000, 'tarification' => [
                'weeks' => 1, 'lines' => [],
            ]]))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertEquals(16000, $subscription->amount_student);
        $this->assertSame(40, $subscription->tarification->weeks);
        $this->assertSame(3, $subscription->costLines()->count());
    }

    private int $seq = 0;

    private function player(array $attributes = []): Player
    {
        return Player::create([
            'membership_id' => '84'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Ali', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
            ...$attributes,
        ]);
    }

    #[Test]
    public function coach_pay_never_reaches_a_user_without_finance_rights(): void
    {
        $subscription = $this->subscription();
        $this->actingAs($this->admin())->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $user = $this->userWithoutFinance();

        foreach ([route('subscriptions.create'), route('subscriptions.edit', $subscription), route('subscriptions.show', $subscription)] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->missing('tarification'))
                ->assertDontSee('Head coach');
        }
    }

    #[Test]
    public function the_subscription_prop_itself_never_carries_the_costs(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($admin)->get(route('subscriptions.show', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('subscription.tarification')
                ->missing('subscription.cost_lines'));
    }

    #[Test]
    public function the_prop_carries_saved_values_for_finance_users(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($admin)->get(route('subscriptions.edit', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tarification.saved.sessions_per_week', 3)
                ->where('tarification.saved.expected_payment_rate', 85)
                ->where('tarification.saved.lines.0.label', 'Head coach')
                ->where('tarification.saved.lines.2.unit', 'per_trip'));
    }

    #[Test]
    public function a_new_subscription_gets_defaults_and_the_roster(): void
    {
        $this->player();

        $this->actingAs($this->admin())->get(route('subscriptions.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tarification.saved.weeks', 40)
                ->where('tarification.saved.expected_payment_rate', 100)
                ->where('tarification.saved.lines', [])
                ->where('tarification.collected', 0)
                ->where('tarification.realRate', null)
                ->has('tarification.roster', 1));
    }

    #[Test]
    public function the_roster_groups_players_once_and_leaves_out_archived_and_exempt(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $football = Branch::create(['name' => 'Football']);
        $swimming = Branch::create(['name' => 'Swimming']);
        $subscription = $this->subscription();

        $twoBranches = [$this->player(['category_id' => $category->id]), $this->player(['category_id' => $category->id])];
        foreach ($twoBranches as $player) {
            $player->branches()->attach([$swimming->id, $football->id]);
        }
        $this->player(['category_id' => $category->id, 'is_student' => false]);
        $this->player(['archived' => true]);

        $exempt = $this->player(['category_id' => $category->id]);
        PlayerSubscription::create([
            'player_id' => $exempt->id, 'subscription_id' => $subscription->id, 'year' => $subscription->year,
            'status_at_time' => 'student', 'is_mandatory' => true, 'amount_owed' => 15000, 'amount_paid' => 0, 'is_exempt' => true,
        ]);

        $this->actingAs($this->admin())->get(route('subscriptions.show', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tarification.roster', 2)
                ->where('tarification.roster', function ($roster) use ($category, $football, $swimming) {
                    $groups = collect($roster)->keyBy('is_student');

                    return $groups[1]['count'] === 2
                        && $groups[1]['category_id'] === $category->id
                        && $groups[1]['branch_ids'] === collect([$football->id, $swimming->id])->sort()->values()->all()
                        && $groups[0]['count'] === 1
                        && $groups[0]['branch_ids'] === [];
                }));
    }

    #[Test]
    public function the_schedule_counts_slots_still_valid(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $slot = ['category_id' => $category->id, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2020-01-01'];
        TrainingSchedule::create(['weekday' => 1, ...$slot]);
        TrainingSchedule::create(['weekday' => 3, 'valid_to' => now()->addYear()->toDateString(), ...$slot]);
        TrainingSchedule::create(['weekday' => 5, 'valid_to' => '2021-01-01', ...$slot]);

        $this->actingAs($this->admin())->get(route('subscriptions.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tarification.schedule', 1)
                ->where('tarification.schedule.0.slots', 2)
                ->where('tarification.schedule.0.category_id', $category->id)
                ->where('tarification.schedule.0.branch_id', null));
    }

    #[Test]
    public function the_real_rate_is_collected_over_the_net_owed_by_non_exempt_players(): void
    {
        $subscription = $this->subscription();
        $row = ['subscription_id' => $subscription->id, 'year' => $subscription->year, 'status_at_time' => 'student', 'is_mandatory' => true];

        PlayerSubscription::create(['player_id' => $this->player()->id, 'amount_owed' => 10000, 'amount_paid' => 5000, ...$row]);
        PlayerSubscription::create(['player_id' => $this->player()->id, 'amount_owed' => 10000, 'amount_paid' => 0, 'discount_type' => 'amount', 'discount_value' => 5000, ...$row]);
        PlayerSubscription::create(['player_id' => $this->player()->id, 'amount_owed' => 10000, 'amount_paid' => 0, 'is_exempt' => true, ...$row]);

        $this->actingAs($this->admin())->get(route('subscriptions.show', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tarification.collected', 5000)
                ->where('tarification.realRate', 33.3));
    }
}
