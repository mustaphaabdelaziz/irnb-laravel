<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionsIndexTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Season 2026/2027 (key 2027) is the current one.
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    private function props(array $query = []): array
    {
        $this->admin ??= User::factory()->admin()->create();

        return $this->actingAs($this->admin)->get(route('transactions.index', $query))->assertOk()->viewData('page')['props'];
    }

    private function tx(string $description, string $date, array $extra = []): Transaction
    {
        // $extra first: its keys win over the defaults.
        return Transaction::create($extra + [
            'description' => $description, 'amount' => 100, 'transaction_date' => $date,
            'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);
    }

    /** @return list<string> */
    private function listed(array $query = []): array
    {
        return collect($this->props($query)['transactions']['data'])->pluck('description')->sort()->values()->all();
    }

    private function tile(array $props, string $key): float
    {
        return (float) collect($props['strip'])->firstWhere('key', $key)['value'];
    }

    private function account(string $name): FinanceAccount
    {
        return FinanceAccount::create(['name' => $name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD']);
    }

    #[Test]
    public function the_list_opens_on_the_current_season(): void
    {
        $this->tx('now', '2026-10-01');
        $this->tx('last-season', '2026-03-01');

        $props = $this->props();

        $this->assertSame(['now'], collect($props['transactions']['data'])->pluck('description')->all());
        $this->assertSame(2027, $props['currentSeason']);
        $this->assertArrayNotHasKey('fiscal_year', $props['filters']);
        $this->assertSame(['value' => '2027', 'label' => '2026/2027'], $props['seasons'][0]);
        $this->assertContains(['value' => '2026', 'label' => '2025/2026'], $props['seasons']);
        $this->assertArrayNotHasKey('stats', $props);
    }

    #[Test]
    public function every_season_or_one_past_season_can_be_chosen(): void
    {
        $this->tx('now', '2026-10-01');
        $this->tx('last-season', '2026-03-01');

        $this->assertSame(['last-season', 'now'], $this->listed(['fiscal_year' => 'all']));
        $this->assertSame(['last-season'], $this->listed(['fiscal_year' => '2026']));
        $this->assertSame(['all'], $this->props(['fiscal_year' => 'all'])['filters']['fiscal_year']);
    }

    #[Test]
    public function a_date_range_alone_decides_the_days_whatever_the_season(): void
    {
        $this->tx('march', '2026-03-10');
        $this->tx('april', '2026-04-10 18:30:00');
        $this->tx('october', '2026-10-01');

        // Inclusive of the whole last day, and not narrowed to the current season.
        $this->assertSame(['april', 'march'], $this->listed(['date_from' => '2026-03-01', 'date_to' => '2026-04-10']));
        // A malformed date is ignored rather than compared as text.
        $this->assertSame(['october'], $this->listed(['date_from' => 'yesterday']));
    }

    #[Test]
    public function the_strip_follows_the_filters_but_debts_stay_club_wide(): void
    {
        $a = $this->account('A');
        $b = $this->account('B');
        $this->tx('in-a', '2026-10-01', ['amount' => 900, 'finance_account_id' => $a->id]);
        $this->tx('out-a', '2026-10-02', ['amount' => 400, 'transaction_type' => 'expense', 'category' => 'supplies', 'finance_account_id' => $a->id]);
        $this->tx('in-b', '2026-10-03', ['amount' => 50, 'finance_account_id' => $b->id]);
        Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'lastname' => 'Benali', 'outstanding_debt' => 1200]);

        $props = $this->props(['finance_account_id' => [$a->id]]);
        $this->assertSame(['tx_income', 'tx_expense', 'tx_net', 'tx_debts'], collect($props['strip'])->pluck('key')->all());
        $this->assertSame(900.0, $this->tile($props, 'tx_income'));
        $this->assertSame(400.0, $this->tile($props, 'tx_expense'));
        $this->assertSame(500.0, $this->tile($props, 'tx_net'));
        $this->assertSame(1200.0, $this->tile($props, 'tx_debts'));

        $all = $this->props();
        $this->assertSame(950.0, $this->tile($all, 'tx_income'));
        $this->assertSame(1200.0, $this->tile($all, 'tx_debts'));
    }

    #[Test]
    public function each_statistics_card_ignores_its_own_filter(): void
    {
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();
        $grants = FinanceCategory::where('code', 'GRT')->firstOrFail();
        $equipment = FinanceCategory::where('code', 'EQP')->firstOrFail();
        $this->tx('d', '2026-10-01', ['amount' => 300, 'finance_category_id' => $donations->id]);
        $this->tx('g', '2026-10-02', ['amount' => 200, 'finance_category_id' => $grants->id]);
        $this->tx('e', '2026-10-03', ['amount' => 80, 'transaction_type' => 'expense', 'finance_category_id' => $equipment->id]);

        $props = $this->props(['finance_category_id' => [$donations->id]]);

        // The list is narrowed…
        $this->assertSame(['d'], collect($props['transactions']['data'])->pluck('description')->all());
        // …the category cards are not, so another bar can still be clicked.
        $income = collect($props['incomeByCategory'])->keyBy('key');
        $this->assertEquals(300, $income[$donations->id]['count']);
        $this->assertEquals(200, $income[$grants->id]['count']);
        $this->assertSame($grants->localized_name, $income[$grants->id]['label']);
        $this->assertEquals(80, collect($props['expenseByCategory'])->firstWhere('key', $equipment->id)['count']);
        // The caisse card follows the category filter: one entry, labelled.
        $this->assertSame(1, collect($props['byAccount'])->sum('count'));
        $this->assertNotSame('', collect($props['byAccount'])->first()['label']);
    }

    #[Test]
    public function a_payment_status_from_a_link_filters_and_shows_and_the_old_slug_filter_is_gone(): void
    {
        $this->tx('paid', '2026-10-01');
        $this->tx('partial', '2026-10-02', ['status' => 'Partial']);

        $props = $this->props(['status' => 'Partial']);
        $this->assertSame(['partial'], collect($props['transactions']['data'])->pluck('description')->all());
        $this->assertSame(['Partial'], $props['filters']['status']);

        // `category` (the stored slug) had no control on the page: it no longer filters.
        $this->assertSame(['paid', 'partial'], $this->listed(['category' => 'nothing-matches']));
    }

    #[Test]
    public function the_page_size_can_be_chosen(): void
    {
        foreach (range(1, 30) as $i) {
            $this->tx("t{$i}", '2026-10-01');
        }

        $this->assertSame(25, $this->props()['transactions']['per_page']);
        $this->assertSame(50, $this->props(['per_page' => 50])['transactions']['per_page']);
        $this->assertSame(25, $this->props(['per_page' => 7])['transactions']['per_page']);
        $this->assertSame('50', $this->props(['per_page' => 50])['filters']['per_page']);
    }

    #[Test]
    public function search_finds_a_category_by_its_name_never_by_its_slug(): void
    {
        $works = FinanceCategory::create(['type' => 'expense', 'name' => 'مصاريف أشغال المقر', 'is_active' => true]);
        $this->tx('hq', '2026-10-01', ['transaction_type' => 'expense', 'finance_category_id' => $works->id, 'category' => 'msaryf_ashghal_almkr']);
        $this->tx('other', '2026-10-02');

        $this->assertSame(['hq'], $this->listed(['search' => 'أشغال']));
        $this->assertSame([], $this->listed(['search' => 'msaryf']));
    }

    #[Test]
    public function finance_categories_come_with_their_usage_including_inactive_ones(): void
    {
        $old = FinanceCategory::create(['type' => 'income', 'name' => 'Old', 'is_active' => false]);
        $this->tx('x', '2026-10-01', ['finance_category_id' => $old->id]);

        $row = collect($this->props()['financeCategories'])->firstWhere('id', $old->id);
        $this->assertSame(1, $row['transactions_count']);
    }

    #[Test]
    public function pagination_speaks_the_users_language(): void
    {
        foreach (range(1, 26) as $i) {
            $this->tx("t{$i}", '2026-10-01');
        }

        $this->admin = User::factory()->admin()->create(['preferred_lng' => 'ar']);
        $links = $this->props()['transactions']['links'];
        $this->assertSame('السابق', $links[0]['label']);
        $this->assertSame('التالي', end($links)['label']);

        $this->admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $links = $this->props()['transactions']['links'];
        $this->assertSame('Précédent', $links[0]['label']);
        $this->assertSame('Suivant', end($links)['label']);
    }
}
