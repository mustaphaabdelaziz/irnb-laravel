<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Player;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardPageTest extends TestCase
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

        $role = Role::create([
            'key' => 'coach',
            'name' => ['en' => 'Coach'],
            'permissions' => $permissions,
        ]);

        return User::factory()->create([
            'privileges' => [],
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Headers for a partial reload of one tab.
     *
     * The asset version has to be the live one — Inertia answers a mismatch
     * with a 409 telling the client to hard-reload, not with the payload.
     *
     * @return array<string, string>
     */
    private function partialHeaders(string $tab): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => $tab,
        ];
    }

    private function seedSomeData(): void
    {
        Player::create([
            'membership_id' => '000001',
            'firstname' => 'Sami',
            'lastname' => 'Khelifi',
        ]);

        Transaction::create([
            'amount' => 500,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => 'donation',
            'status' => 'Paid',
            'fiscal_year' => now()->year,
        ]);
    }

    #[Test]
    public function it_renders_the_shell_with_filters_and_hero_tiles(): void
    {
        $this->seedSomeData();

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('filters')
                ->where('filters.range', 'month')
                ->where('filters.tab', 'overview')
                ->has('hero', 6)
                ->has('branches')
                ->has('can'));
    }

    #[Test]
    public function tab_payloads_are_absent_until_they_are_asked_for(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('overview')
                ->missing('finance')
                ->missing('members')
                ->missing('operations'));
    }

    #[Test]
    public function a_partial_reload_returns_only_the_requested_tab(): void
    {
        $this->seedSomeData();

        // A partial reload answers with JSON, not a view, so the page object is
        // read straight off the response rather than through assertInertia.
        $response = $this->actingAs($this->admin())
            ->get(route('dashboard'), $this->partialHeaders('overview'))
            ->assertOk();

        $props = $response->json('props');

        $this->assertArrayHasKey('cashFlow', $props['overview']);
        $this->assertCount(4, $props['overview']['debtAging']);
        $this->assertArrayNotHasKey('finance', $props);
        $this->assertArrayNotHasKey('hero', $props);
    }

    #[Test]
    public function the_filter_state_round_trips_through_the_query_string(): void
    {
        $branch = Branch::create(['name' => 'North']);

        $this->actingAs($this->admin())
            ->get(route('dashboard', ['range' => 'year', 'branch' => $branch->id, 'compare' => '0', 'tab' => 'members']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.range', 'year')
                ->where('filters.branch', $branch->id)
                ->where('filters.compare', false)
                ->where('filters.tab', 'members'));
    }

    #[Test]
    public function a_user_without_finance_permission_cannot_pull_the_finance_tab(): void
    {
        $this->seedSomeData();

        $response = $this->actingAs($this->userWithoutFinance())
            ->get(route('dashboard'), $this->partialHeaders('finance'))
            ->assertOk();

        $this->assertNull($response->json('props.finance'));
    }

    #[Test]
    public function the_page_tells_the_client_which_tabs_are_visible(): void
    {
        $this->actingAs($this->userWithoutFinance())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.finance', false)
                ->where('can.members', true));
    }

    #[Test]
    public function the_first_paint_stays_within_its_query_budget(): void
    {
        $this->seedSomeData();
        $admin = $this->admin();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        // Six tiles, each needing a value, a previous-period value and a
        // twelve-month series, plus the shell's own lookups. Measured at 18;
        // the headroom is small on purpose — one N+1 adds ten and trips this.
        $this->assertLessThanOrEqual(
            20,
            $queries,
            "First paint ran {$queries} queries — the hero row is leaking work.",
        );
    }

    #[Test]
    public function the_overview_tab_stays_within_its_query_budget(): void
    {
        $this->seedSomeData();
        $admin = $this->admin();

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->actingAs($admin)->get(route('dashboard'), $this->partialHeaders('overview'))->assertOk();

        // Measured at 11: four widgets plus the shared props every request pays.
        $this->assertLessThanOrEqual(
            13,
            count($sql),
            'The overview tab ran '.count($sql)." queries — a widget is leaking work:\n".implode("\n", $sql),
        );
    }

    #[Test]
    public function an_empty_install_still_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('hero', 6));
    }
}
