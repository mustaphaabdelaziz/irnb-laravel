<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionKindsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function makePlayer(?Category $category, bool $student = true): Player
    {
        return Player::create([
            'membership_id' => '82'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Kind', 'lastname' => 'Player'.$this->seq,
            'is_student' => $student, 'outstanding_debt' => 0,
            'category_id' => $category?->id,
        ]);
    }

    #[Test]
    public function an_annual_subscription_reads_as_the_season_ending_in_its_year(): void
    {
        $sub = Subscription::create(['name' => 'Cotisation', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000]);

        $this->assertSame('annual', $sub->fresh()->kind);
        $this->assertSame('2025/2026', $sub->year_label);
        $this->assertSame('Cotisation - 2025/2026', $sub->designation);
    }

    #[Test]
    public function an_exceptional_subscription_is_stored_without_year_and_never_mandatory(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), [
            'kind' => 'exceptional',
            'name' => 'T-shirt',
            'year' => 2026,
            'amount_student' => 1500,
            'amount_worker' => 1500,
            'is_mandatory' => true,
        ])->assertSessionHasNoErrors();

        $sub = Subscription::where('name', 'T-shirt')->firstOrFail();
        $this->assertSame('exceptional', $sub->kind);
        $this->assertNull($sub->year);
        $this->assertFalse($sub->is_mandatory);
        $this->assertNull($sub->year_label);
        $this->assertSame('T-shirt', $sub->designation);
    }

    #[Test]
    public function a_request_without_kind_still_creates_an_annual_subscription(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), [
            'name' => 'Cotisation', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000,
        ])->assertSessionHasNoErrors();

        $this->assertSame('annual', Subscription::firstOrFail()->kind);
    }

    #[Test]
    public function an_annual_subscription_requires_a_season(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), [
            'kind' => 'annual', 'name' => 'Cotisation', 'amount_student' => 1000, 'amount_worker' => 2000,
        ])->assertSessionHasErrors('year');
    }

    #[Test]
    public function exceptional_names_must_be_unique(): void
    {
        Subscription::create(['kind' => 'exceptional', 'name' => 'T-shirt', 'year' => null, 'amount_student' => 1, 'amount_worker' => 1]);

        $this->actingAs($this->admin())->post(route('subscriptions.store'), [
            'kind' => 'exceptional', 'name' => 'T-shirt', 'amount_student' => 1500, 'amount_worker' => 1500,
        ])->assertSessionHasErrors('name');
    }

    #[Test]
    public function each_category_can_carry_its_own_student_and_worker_price(): void
    {
        $u13 = Category::create(['name' => 'U13']);
        $seniors = Category::create(['name' => 'Seniors']);

        $this->actingAs($this->admin())->post(route('subscriptions.store'), [
            'kind' => 'annual', 'name' => 'Cotisation', 'year' => 2026,
            'amount_student' => 1000, 'amount_worker' => 2000,
            'category_ids' => [$u13->id, $seniors->id],
            'category_prices' => [
                $u13->id => ['amount_student' => 800, 'amount_worker' => ''],
                $seniors->id => ['amount_student' => '', 'amount_worker' => 3000],
            ],
        ])->assertSessionHasNoErrors();

        $sub = Subscription::with('categories')->firstOrFail();

        // Override where set, default where left blank.
        $this->assertSame(800.0, $sub->amountFor($this->makePlayer($u13, student: true)));
        $this->assertSame(2000.0, $sub->amountFor($this->makePlayer($u13, student: false)));
        $this->assertSame(1000.0, $sub->amountFor($this->makePlayer($seniors, student: true)));
        $this->assertSame(3000.0, $sub->amountFor($this->makePlayer($seniors, student: false)));
    }

    #[Test]
    public function assigning_uses_the_category_price(): void
    {
        $u13 = Category::create(['name' => 'U13']);
        $sub = Subscription::create(['name' => 'Cotisation', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 2000, 'is_mandatory' => true]);
        $sub->categories()->attach($u13->id, ['amount_student' => 700]);
        $player = $this->makePlayer($u13);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id])
            ->assertSessionHasNoErrors();

        $ps = PlayerSubscription::where('player_id', $player->id)->firstOrFail();
        $this->assertEquals(700, $ps->amount_owed);
        $this->assertEquals(700, $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function an_exceptional_charge_is_assigned_but_never_counted_as_debt(): void
    {
        $sub = Subscription::create(['kind' => 'exceptional', 'name' => 'T-shirt', 'year' => null, 'amount_student' => 1500, 'amount_worker' => 1500, 'is_mandatory' => true]);
        $player = $this->makePlayer(null);

        $this->actingAs($this->admin())
            ->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id])
            ->assertSessionHasNoErrors();

        $ps = PlayerSubscription::where('player_id', $player->id)->firstOrFail();
        $this->assertFalse((bool) $ps->is_mandatory);
        $this->assertSame((int) now()->year, (int) $ps->year);
        $this->assertEquals(1500, $ps->amount_owed);
        $this->assertEquals(0, $player->fresh()->outstanding_debt);
    }

    #[Test]
    public function the_kind_cannot_change_once_players_are_assigned(): void
    {
        $sub = Subscription::create(['name' => 'Cotisation', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1000]);
        $sub->assignTo($this->makePlayer(null));

        $this->actingAs($this->admin())->put(route('subscriptions.update', $sub), [
            'kind' => 'exceptional', 'name' => 'Cotisation', 'amount_student' => 1000, 'amount_worker' => 1000,
        ])->assertSessionHasErrors('kind');

        $this->assertSame('annual', $sub->fresh()->kind);
    }

    #[Test]
    public function the_edit_page_lists_seasons_by_label(): void
    {
        $sub = Subscription::create(['name' => 'Cotisation', 'year' => 2015, 'amount_student' => 1000, 'amount_worker' => 1000]);

        $this->actingAs($this->admin())->get(route('subscriptions.edit', $sub))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscription.year_label', '2014/2015')
                // An old season outside the default window is still offered.
                ->where('seasons', fn ($seasons) => collect($seasons)->contains(fn ($s) => $s['value'] === 2015 && $s['label'] === '2014/2015')));
    }
}
