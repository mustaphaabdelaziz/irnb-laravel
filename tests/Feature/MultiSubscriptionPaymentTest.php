<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Several subscriptions paid with one amount, in the order the cashier ticked them, on one receipt. */
class MultiSubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->player = Player::create([
            'membership_id' => '8300000001',
            'firstname' => 'Amine', 'lastname' => 'Haddad',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function debt(string $label, float $owed): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $this->player->id, 'subscription_id' => null, 'transaction_id' => null,
            'label' => $label, 'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
    }

    private function catalog(string $name, array $branches = []): Subscription
    {
        $sub = Subscription::create([
            'name' => $name, 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));

        return $sub;
    }

    private function pay(array $payables, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('players.transactions.store', $this->player), [
            'category' => 'subscription',
            'payment_method' => 'cash',
            'payables' => $payables,
            ...$extra,
        ]);
    }

    /** @return Collection<int, Transaction> */
    private function payments()
    {
        return Transaction::where('related_entity_id', $this->player->id)->orderBy('id')->get();
    }

    #[Test]
    public function the_amount_pays_each_ticked_subscription_in_full_in_the_order_picked(): void
    {
        $a = $this->debt('A', 1000);
        $b = $this->debt('B', 2000);
        $c = $this->debt('C', 500);

        $this->pay([
            ['player_subscription_id' => $c->id],
            ['player_subscription_id' => $a->id],
            ['player_subscription_id' => $b->id],
        ], ['amount' => 2000])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(500.0, (float) $c->fresh()->amount_paid);
        $this->assertSame(1000.0, (float) $a->fresh()->amount_paid);
        $this->assertSame(500.0, (float) $b->fresh()->amount_paid);

        $payments = $this->payments();
        $this->assertSame([$c->id, $a->id, $b->id], $payments->pluck('player_subscription_id')->all());
        $this->assertSame(['Paid', 'Paid', 'Partial'], $payments->pluck('status')->all());
        $this->assertNotNull($payments->first()->payment_group);
        $this->assertCount(1, $payments->pluck('payment_group')->unique());
        $this->assertSame(1500.0, (float) $this->player->fresh()->outstanding_debt);
    }

    #[Test]
    public function what_is_paid_beyond_the_total_is_a_donation_of_the_same_payment(): void
    {
        $a = $this->debt('A', 1000);
        $b = $this->debt('B', 500);

        $this->pay([['player_subscription_id' => $a->id], ['player_subscription_id' => $b->id]], ['amount' => 1800]);

        $payments = $this->payments();
        $this->assertSame(['subscription', 'subscription', 'donation'], $payments->pluck('category')->all());
        $this->assertSame(300.0, (float) $payments->last()->amount);
        $this->assertCount(1, $payments->pluck('payment_group')->unique());
    }

    #[Test]
    public function catalog_subscriptions_are_assigned_and_paid_with_the_rest(): void
    {
        $debt = $this->debt('Old', 700);
        $card = $this->catalog('Club card');

        $this->pay([['subscription_id' => $card->id], ['player_subscription_id' => $debt->id]], ['amount' => 2200])
            ->assertSessionHasNoErrors();

        $line = PlayerSubscription::where('subscription_id', $card->id)->sole();
        $this->assertSame(1500.0, (float) $line->amount_paid);
        $this->assertSame(700.0, (float) $debt->fresh()->amount_paid);
    }

    #[Test]
    public function exempt_waives_every_ticked_subscription_without_a_payment(): void
    {
        $one = $this->catalog('One');
        $two = $this->catalog('Two');

        $this->pay([['subscription_id' => $one->id], ['subscription_id' => $two->id]], ['is_exempt' => true, 'amount' => 3000])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PlayerSubscription::where('player_id', $this->player->id)->where('is_exempt', true)->count());
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function one_subscription_that_does_not_apply_stops_the_whole_payment(): void
    {
        $open = $this->catalog('Club card');
        $swim = $this->catalog('Swim season', [Branch::create(['name' => 'Swim'])]);

        $this->pay([['subscription_id' => $open->id], ['subscription_id' => $swim->id]], ['amount' => 3000])
            ->assertSessionHasErrors('subscription_id');

        $this->assertSame(0, PlayerSubscription::count());
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function the_payment_prints_as_one_ticket_listing_every_line_and_the_total(): void
    {
        $a = $this->debt('Annual fee', 1000);
        $b = $this->debt('Kit fee', 600);
        $this->pay([['player_subscription_id' => $a->id], ['player_subscription_id' => $b->id]], ['amount' => 1600]);
        [$first, $second] = $this->payments()->all();

        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('streamRoll')->andReturnUsing(function (string $html) use ($seen) {
                $seen[] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        // Printed from the second line: still the group's receipt, filed under the first.
        $this->actingAs($this->admin)->get(route('transactions.ticket', $second))->assertOk();

        $html = $seen[0];
        $this->assertStringContainsString('Annual fee', $html);
        $this->assertStringContainsString('Kit fee', $html);
        $this->assertStringContainsString('1,600.00', $html);
        $this->assertStringContainsString('#'.str_pad((string) $first->id, 6, '0', STR_PAD_LEFT), $html);

        $print = ReceiptPrint::sole();
        $this->assertSame($first->id, $print->transaction_id);
        $this->assertSame('1600.00', $print->snapshot['amount']);
        $this->assertCount(2, $print->snapshot['lines']);

        // A line cancelled afterwards: the paper no longer matches the books.
        $this->delete(route('players.transactions.destroy', [$this->player, $second]));

        $result = $this->get(route('receipts.verify', ['code' => $print->code]))->inertiaProps('result');
        $this->assertSame('modified', $result['status']);
        $this->assertContains('amount', array_column($result['differences'], 'field'));
    }

    #[Test]
    public function the_grouped_a4_receipt_and_ticket_render_as_pdfs(): void
    {
        $a = $this->debt('Annual fee', 1000);
        $b = $this->debt('Kit fee', 600);
        $this->pay([['player_subscription_id' => $a->id], ['player_subscription_id' => $b->id]], ['amount' => 1200]);
        $first = $this->payments()->first();

        foreach (['transactions.receipt', 'transactions.ticket'] as $route) {
            $response = $this->get(route($route, $first))->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    #[Test]
    public function an_edited_line_stays_in_its_payment(): void
    {
        $a = $this->debt('A', 1000);
        $b = $this->debt('B', 1000);
        $this->pay([['player_subscription_id' => $a->id], ['player_subscription_id' => $b->id]], ['amount' => 2000]);
        [, $second] = $this->payments()->all();

        $this->put(route('players.transactions.update', [$this->player, $second]), ['amount' => 800, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $replacement = Transaction::where('archived', false)->latest('id')->first();
        $this->assertNotSame($second->id, $replacement->id);
        $this->assertSame($second->payment_group, $replacement->payment_group);
        $this->assertCount(2, $replacement->receiptLines());
    }

    #[Test]
    public function a_single_subscription_still_pays_as_before(): void
    {
        $a = $this->debt('A', 1000);

        $this->pay([['player_subscription_id' => $a->id]], ['amount' => 1000]);

        $this->assertNull(Transaction::sole()->payment_group);
        $this->assertSame(1000.0, (float) $a->fresh()->amount_paid);
    }
}
