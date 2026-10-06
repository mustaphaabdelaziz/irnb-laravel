<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The select filters of the Transactions, Users, Subscriptions and Equipment
 * catalog lists take several values (OR within a filter, AND across), and a
 * single scalar value from an old link still works.
 */
class ListMultiSelectFiltersTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function props(string $route, array $query = []): array
    {
        $this->admin ??= User::factory()->admin()->create(['email_verified_at' => now(), 'name' => 'zz-admin']);

        return $this->actingAs($this->admin)
            ->get(route($route, $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function account(string $name): FinanceAccount
    {
        return FinanceAccount::create([
            'name' => $name, 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0,
            'currency' => 'DZD', 'is_active' => true,
        ]);
    }

    private function tx(string $title, array $attributes): Transaction
    {
        return Transaction::create([
            'title' => $title,
            'amount' => 100,
            'transaction_date' => '2026-03-01',
            'transaction_type' => 'income',
            'category' => 'donation',
            'payment_method' => 'cash',
            'status' => 'Paid',
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function txTitles(array $query): array
    {
        return collect($this->props('transactions.index', $query)['transactions']['data'])
            ->pluck('description')->sort()->values()->all();
    }

    #[Test]
    public function transactions_filter_by_several_types_categories_and_registers(): void
    {
        $income = FinanceCategory::where('type', 'income')->orderBy('id')->firstOrFail();
        $expense = FinanceCategory::where('type', 'expense')->orderBy('id')->firstOrFail();
        $otherIncome = FinanceCategory::where('type', 'income')->where('id', '!=', $income->id)->orderBy('id')->firstOrFail();
        $a = $this->account('A');
        $b = $this->account('B');
        $c = $this->account('C');

        $this->tx('t1', ['description' => 'one', 'finance_category_id' => $income->id, 'finance_account_id' => $a->id]);
        $this->tx('t2', ['description' => 'two', 'transaction_type' => 'expense', 'finance_category_id' => $expense->id, 'finance_account_id' => $b->id]);
        $this->tx('t3', ['description' => 'three', 'finance_category_id' => $otherIncome->id, 'finance_account_id' => $c->id]);

        $this->assertSame(['one', 'three', 'two'], $this->txTitles(['type' => ['income', 'expense']]));
        $this->assertSame(['one', 'two'], $this->txTitles(['finance_category_id' => [$income->id, $expense->id]]));
        $this->assertSame(['three', 'two'], $this->txTitles(['finance_account_id' => [$b->id, $c->id]]));
        // AND across filters.
        $this->assertSame(['one'], $this->txTitles(['type' => ['income'], 'finance_account_id' => [$a->id, $b->id]]));
        // Scalar compat.
        $this->assertSame(['two'], $this->txTitles(['type' => 'expense']));

        $filters = $this->props('transactions.index', ['type' => 'expense', 'search' => 'x'])['filters'];
        $this->assertSame(['expense'], $filters['type']);
        $this->assertSame('x', $filters['search']);
    }

    #[Test]
    public function the_transactions_export_honours_arrays(): void
    {
        $a = $this->account('A');
        $b = $this->account('B');
        $c = $this->account('C');
        $this->tx('t1', ['description' => 'desc-one', 'finance_account_id' => $a->id]);
        $this->tx('t2', ['description' => 'desc-two', 'finance_account_id' => $b->id]);
        $this->tx('t3', ['description' => 'desc-three', 'finance_account_id' => $c->id]);

        $content = $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))
            ->get(route('transactions.export', ['format' => 'csv', 'finance_account_id' => [$a->id, $c->id]]))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('desc-one', $content);
        $this->assertStringContainsString('desc-three', $content);
        $this->assertStringNotContainsString('desc-two', $content);
    }

    #[Test]
    public function users_filter_by_approval_activity_and_role(): void
    {
        User::factory()->create(['name' => 'pending-user', 'approved' => false, 'is_active' => false, 'privileges' => ['user']]);
        User::factory()->create(['name' => 'active-admin', 'approved' => true, 'is_active' => true, 'privileges' => ['admin']]);
        User::factory()->create(['name' => 'inactive-user', 'approved' => true, 'is_active' => false, 'privileges' => ['user']]);
        User::factory()->create(['name' => 'pending-active', 'approved' => false, 'is_active' => true, 'privileges' => ['user']]);

        $names = fn (array $query) => collect($this->props('users.index', $query)['users']['data'])
            ->pluck('name')->reject(fn ($n) => $n === 'zz-admin')->sort()->values()->all();

        // Approval and activity are separate filters: AND between them.
        $this->assertSame(['pending-user'], $names(['approval' => ['pending'], 'activity' => ['inactive']]));
        // OR within one filter.
        $this->assertSame(['active-admin', 'inactive-user', 'pending-active', 'pending-user'], $names(['approval' => ['pending', 'approved']]));
        $this->assertSame(['inactive-user', 'pending-user'], $names(['activity' => 'inactive']));
        // Role filter: role ids, `none` (no role), `superadmin`.
        $this->assertSame(['active-admin', 'inactive-user', 'pending-active', 'pending-user'], $names(['role' => ['none']]));
        $this->assertSame(['active-admin', 'inactive-user', 'pending-active', 'pending-user'], $names(['role' => ['none', 'bogus']]));
        $this->assertSame(['active-admin', 'inactive-user'], $names(['approval' => ['approved'], 'role' => ['none']]));

        $filters = $this->props('users.index', ['approval' => ['pending', 'bogus'], 'activity' => ['active']])['filters'];
        $this->assertSame(['pending'], $filters['approval']);
        $this->assertSame(['active'], $filters['activity']);
    }

    #[Test]
    public function the_user_role_filter_echoes_and_applies_only_known_roles(): void
    {
        User::factory()->create(['name' => 'plain-user', 'privileges' => ['user']]);

        $filters = $this->props('users.index', ['role' => ['none', 'bogus']])['filters'];
        $this->assertSame(['none'], $filters['role']);

        // Junk alone is no filter at all: not echoed, and every user listed.
        $props = $this->props('users.index', ['role' => ['bogus', '<script>']]);
        $this->assertArrayNotHasKey('role', $props['filters']);
        $this->assertContains('plain-user', collect($props['users']['data'])->pluck('name')->all());
    }

    #[Test]
    public function old_user_status_links_still_work(): void
    {
        User::factory()->create(['name' => 'pending-user', 'approved' => false, 'is_active' => false]);
        User::factory()->create(['name' => 'active-user', 'approved' => true, 'is_active' => true]);

        $names = fn (array $query) => collect($this->props('users.index', $query)['users']['data'])
            ->pluck('name')->reject(fn ($n) => $n === 'zz-admin')->sort()->values()->all();

        $this->assertSame(['pending-user'], $names(['status' => 'pending']));
        $this->assertSame(['active-user'], $names(['status' => 'active']));
        // The old parameter is echoed as the new filter it maps to.
        $filters = $this->props('users.index', ['status' => 'inactive'])['filters'];
        $this->assertSame(['inactive'], $filters['activity']);
        $this->assertArrayNotHasKey('approval', $filters);
    }

    private function subscription(string $name, array $attributes = [], array $branchIds = []): Subscription
    {
        $sub = Subscription::create([
            'name' => $name,
            'year' => 2026,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
            ...$attributes,
        ]);
        $sub->branches()->attach($branchIds);

        return $sub;
    }

    #[Test]
    public function subscriptions_filter_by_several_branches_kinds_and_seasons(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swim']);
        $judo = Branch::create(['name' => 'Judo']);
        $this->subscription('S-football', ['year' => 2025], [$football->id]);
        $this->subscription('S-swim', ['year' => 2026], [$swim->id]);
        $this->subscription('S-judo', ['year' => 2027], [$judo->id]);
        $this->subscription('S-trip', ['kind' => Subscription::KIND_EXCEPTIONAL, 'year' => null]);

        $names = fn (array $query) => collect($this->props('subscriptions.index', $query)['subscriptions']['data'])
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['S-football', 'S-swim'], $names(['branch_id' => [$football->id, $swim->id]]));
        $this->assertSame(['S-judo', 'S-swim'], $names(['year' => [2026, 2027]]));
        $this->assertSame(['S-football', 'S-judo', 'S-swim', 'S-trip'], $names(['kind' => [Subscription::KIND_ANNUAL, Subscription::KIND_EXCEPTIONAL]]));
        $this->assertSame(['S-trip'], $names(['kind' => Subscription::KIND_EXCEPTIONAL]));
        $this->assertSame(['S-football'], $names(['branch_id' => $football->id]));
        $this->assertSame([(string) $football->id], $this->props('subscriptions.index', ['branch_id' => $football->id])['filters']['branch_id']);
    }

    #[Test]
    public function equipment_catalogs_filter_by_several_categories(): void
    {
        EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Balls']);
        EquipmentCatalog::create(['name' => 'Shirts', 'category' => 'Apparel']);
        EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);

        $names = fn (array $query) => collect($this->props('equipment.catalogs.index', $query)['catalogs']['data'])
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Balls', 'Shirts'], $names(['category' => ['Balls', 'Apparel']]));
        $this->assertSame(['Cones'], $names(['category' => 'Training']));
        $this->assertSame(['Training'], $this->props('equipment.catalogs.index', ['category' => 'Training'])['filters']['category']);
    }
}
