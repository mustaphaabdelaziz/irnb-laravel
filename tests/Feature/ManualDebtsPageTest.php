<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualDebtsPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function makePlayer(string $first = 'P', string $last = 'Test'): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $first,
            'lastname' => $last,
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);
    }

    private function makeSubscription(string $name, array $branchIds = []): Subscription
    {
        $sub = Subscription::create([
            'name' => $name,
            'year' => 2026,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        if ($branchIds) {
            $sub->branches()->attach($branchIds);
        }

        return $sub;
    }

    /** A manual debt when $sub is null, otherwise an obligation of that subscription. */
    private function debt(Player $player, ?Subscription $sub, float $owed, float $paid, array $extra = []): PlayerSubscription
    {
        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'subscription_id' => $sub?->id,
            'label' => $sub ? null : 'Old debt',
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'is_legacy' => $sub === null,
            'amount_owed' => $owed,
            'amount_paid' => $paid,
        ], $extra));
    }

    #[Test]
    public function the_scope_keeps_manual_debts_and_branchless_subscriptions_only(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $manual = $this->debt($this->makePlayer(), null, 1500, 0);
        $branchless = $this->debt($this->makePlayer(), $this->makeSubscription('Loose'), 2000, 0);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 2000, 0);

        $this->assertEqualsCanonicalizing(
            [$manual->id, $branchless->id],
            PlayerSubscription::query()->withoutBranch()->pluck('id')->all()
        );
    }

    /** @return array<string, mixed> the page's Inertia props */
    private function page(array $query = [], ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->userWith(['subscriptions' => ['view']]))
            ->get(route('subscriptions.manual-debts', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    #[Test]
    public function the_url_reaches_the_page_not_a_subscription(): void
    {
        $response = $this->actingAs($this->userWith(['subscriptions' => ['view']]))
            ->get('/subscriptions/manual-debts')
            ->assertOk();

        $this->assertSame('Subscriptions/ManualDebts', $response->viewData('page')['component']);
    }

    #[Test]
    public function it_lists_only_obligations_without_a_branch(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $ali = $this->makePlayer('Ali', 'Benali');
        $manual = $this->debt($ali, null, 1500, 500, ['label' => 'Winter kit', 'year' => 2025]);
        $loose = $this->makeSubscription('Loose fee');
        $branchless = $this->debt($this->makePlayer(), $loose, 2000, 0);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 2000, 0);

        $rows = collect($this->page()['debts']['data'])->keyBy('id');

        $this->assertEqualsCanonicalizing([$manual->id, $branchless->id], $rows->keys()->all());

        $row = $rows[$manual->id];
        $this->assertSame($ali->id, $row['player']['id']);
        $this->assertSame($ali->fullname, $row['player']['fullname']);
        $this->assertSame($ali->membership_id, $row['player']['membership_id']);
        $this->assertSame('Winter kit', $row['label']);
        $this->assertSame('2025', $row['season']);
        $this->assertTrue($row['is_manual']);
        $this->assertEquals(1500, $row['owed']);
        $this->assertEquals(500, $row['paid']);
        $this->assertEquals(1000, $row['remaining']);
        $this->assertSame('partial', $row['status']);
        $this->assertNotNull($row['created_at']);

        $this->assertSame('Loose fee', $rows[$branchless->id]['label']);
        $this->assertSame($loose->year_label, $rows[$branchless->id]['season']);
        $this->assertFalse($rows[$branchless->id]['is_manual']);
    }

    #[Test]
    public function totals_equal_the_no_branch_row_of_the_subscriptions_page(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $this->debt($this->makePlayer(), null, 1500, 1500);
        $this->debt($this->makePlayer(), null, 2500, 2000);
        // Half price: 1000 net, nothing paid.
        $this->debt($this->makePlayer(), null, 2000, 0, ['discount_type' => 'percent', 'discount_value' => 50]);
        // Exempt: owed stays gross, nothing remains.
        $this->debt($this->makePlayer(), null, 3000, 0, ['is_exempt' => true]);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 9000, 0);

        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $stats = $this->actingAs($admin)->get(route('subscriptions.index'))->assertOk()->viewData('page')['props']['branchStats'];
        $none = collect($stats)->firstWhere('branch_id', null);

        $strip = collect($this->page()['strip'])->pluck('value', 'key');

        $this->assertSame(4, $strip['debts_count']);
        $this->assertEquals($none['owed'], $strip['debts_owed']);
        $this->assertEquals($none['collected'], $strip['debts_collected']);
        $this->assertEquals($none['outstanding'], $strip['debts_left']);
        $this->assertEquals(9000, $strip['debts_owed']);
        $this->assertEquals(3500, $strip['debts_collected']);
        $this->assertEquals(1500, $strip['debts_left']);
    }

    #[Test]
    public function totals_ignore_the_filters(): void
    {
        $this->debt($this->makePlayer(), null, 1500, 1500);
        $this->debt($this->makePlayer(), null, 2500, 2000);

        $strip = collect($this->page(['status' => 'unpaid', 'search' => 'nobody'])['strip'])->pluck('value', 'key');

        $this->assertSame(2, $strip['debts_count']);
        $this->assertEquals(500, $strip['debts_left']);
    }

    #[Test]
    public function the_status_filter_splits_settled_from_unsettled(): void
    {
        $paid = $this->debt($this->makePlayer(), null, 1500, 1500);
        $partial = $this->debt($this->makePlayer(), null, 2500, 2000);
        $unpaid = $this->debt($this->makePlayer(), null, 1000, 0);
        $exempt = $this->debt($this->makePlayer(), null, 3000, 0, ['is_exempt' => true]);

        $ids = fn (array $query) => array_column($this->page($query)['debts']['data'], 'id');

        $this->assertEqualsCanonicalizing([$partial->id, $unpaid->id], $ids(['status' => 'unpaid']));
        $this->assertEqualsCanonicalizing([$paid->id, $exempt->id], $ids(['status' => 'paid']));
        $this->assertCount(4, $ids(['status' => 'junk']));
        $this->assertArrayNotHasKey('status', $this->page(['status' => 'junk'])['filters']);
    }

    #[Test]
    public function search_matches_player_name_and_label(): void
    {
        $ali = $this->debt($this->makePlayer('Ali', 'Benali'), null, 1000, 0, ['label' => 'Winter kit']);
        $omar = $this->debt($this->makePlayer('Omar', 'Cherif'), null, 1000, 0, ['label' => 'Season 2024']);

        $ids = fn (string $search) => array_column($this->page(['search' => $search])['debts']['data'], 'id');

        $this->assertSame([$ali->id], $ids('benali'));
        $this->assertSame([$ali->id], $ids('Benali Ali'));
        $this->assertSame([$omar->id], $ids('season'));
        $this->assertSame([], $ids('Benali Omar'));
        $this->assertSame('benali', $this->page(['search' => ' benali '])['filters']['search']);
    }

    #[Test]
    public function unsettled_debts_come_first_then_the_newest(): void
    {
        $at = function (PlayerSubscription $debt, string $when): PlayerSubscription {
            $debt->created_at = Carbon::parse($when);
            $debt->save();

            return $debt;
        };
        $paidNew = $at($this->debt($this->makePlayer(), null, 1000, 1000), '2026-11-10 10:00:00');
        $owingOld = $at($this->debt($this->makePlayer(), null, 1000, 0), '2026-01-05 10:00:00');
        $owingNew = $at($this->debt($this->makePlayer(), null, 1000, 0), '2026-11-01 10:00:00');

        $this->assertSame(
            [$owingNew->id, $owingOld->id, $paidNew->id],
            array_column($this->page()['debts']['data'], 'id')
        );
    }

    #[Test]
    public function it_paginates_twenty_five_per_page(): void
    {
        $player = $this->makePlayer();
        foreach (range(1, 27) as $i) {
            $this->debt($player, null, 100, 0, ['label' => "Debt $i"]);
        }

        $first = $this->page()['debts'];
        $this->assertCount(25, $first['data']);
        $this->assertSame(27, $first['total']);
        $this->assertCount(2, $this->page(['page' => 2])['debts']['data']);
    }

    #[Test]
    public function it_needs_subscriptions_view_only(): void
    {
        $this->actingAs($this->userWith(['subscriptions' => ['view']]))
            ->get(route('subscriptions.manual-debts'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view', 'edit']]))
            ->get(route('subscriptions.manual-debts'))->assertForbidden();
    }

    #[Test]
    public function an_empty_bucket_still_renders(): void
    {
        $props = $this->page();

        $this->assertSame([], $props['debts']['data']);
        $this->assertSame(0, collect($props['strip'])->firstWhere('key', 'debts_count')['value']);
    }
}
