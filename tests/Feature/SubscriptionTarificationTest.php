<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionCostLine;
use App\Models\SubscriptionTarification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
