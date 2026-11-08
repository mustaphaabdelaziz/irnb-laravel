<?php

namespace Tests\Feature\Help;

use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\Transaction;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoSubscriptionsSeeder as Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoSubscriptionsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function obligation(int $playerId, string $name): PlayerSubscription
    {
        return PlayerSubscription::where('player_id', $playerId)
            ->whereHas('subscription', fn ($q) => $q->where('name', $name))
            ->firstOrFail();
    }

    #[Test]
    public function the_demo_club_has_subscriptions_payments_and_debts(): void
    {
        $this->seed(HelpDemoSeeder::class);
        // Players are resolved by position in HelpDemoSeeder::PLAYERS order (first 8 are students).
        $ids = Player::orderBy('id')->pluck('id')->values()->all();

        $annual = Subscription::where('name', Demo::ANNUAL)->firstOrFail();
        $this->assertSame(Subscription::KIND_ANNUAL, $annual->kind);
        $this->assertEquals(3000, $annual->amount_student);
        $this->assertEquals(5000, $annual->amount_worker);
        $this->assertSame(Subscription::KIND_EXCEPTIONAL, Subscription::where('name', Demo::TRIP)->value('kind'));
        $this->assertNotNull(FinanceAccount::where('name', Demo::CAISSE)->value('category_id'));

        // أحمد paid the annual in full; his receipt was printed once.
        $ahmed = $this->obligation($ids[0], Demo::ANNUAL);
        $this->assertEquals(0, $ahmed->remaining_amount);
        $paid = Transaction::where('player_subscription_id', $ahmed->id)->firstOrFail();
        $this->assertSame(1, ReceiptPrint::where('transaction_id', $paid->id)->count());
        // Printed as the app prints it: the paper names him (the receipts article shows it).
        $this->assertNotEmpty(ReceiptPrint::where('transaction_id', $paid->id)->first()->snapshot['player_name'] ?? null);

        // ياسين: one grouped payment covering two subscriptions.
        $this->assertEquals(4000, Transaction::where('related_entity_id', $ids[1])->where('category', 'subscription')->sum('amount'));
        $this->assertSame(2, Transaction::where('related_entity_id', $ids[1])->where('category', 'subscription')->count());
        // Players 2 and 4 are priced 2500, not 3000: they also swim, and the club's main branch is
        // Swimming (id 1), so pickPrimary prices them by swimming «أشبال» (override 2500). Designed behaviour.
        $this->assertEquals(2500, $this->obligation($ids[1], Demo::ANNUAL)->amount_owed);
        $this->assertEquals(2500, $this->obligation($ids[3], Demo::ANNUAL)->amount_owed);
        $groups = Transaction::where('related_entity_id', $ids[1])->where('category', 'subscription')->pluck('payment_group')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());

        // أمين: partial; رياض: a donation exists; إسلام: unpaid.
        $this->assertEquals(1000, $this->obligation($ids[2], Demo::ANNUAL)->amount_paid);
        $this->assertEquals(500, Transaction::where('related_entity_id', $ids[3])->where('category', 'donation')->sum('amount'));
        $this->assertEquals(2000, Player::find($ids[2])->outstanding_debt);
        $this->assertEquals(0, Player::find($ids[3])->outstanding_debt);
        $this->assertEquals(0, $this->obligation($ids[4], Demo::ANNUAL)->amount_paid);
        $this->assertEquals(3000, Player::find($ids[4])->outstanding_debt);
        $this->assertEquals(2000, Player::find($ids[5])->outstanding_debt);
        $this->assertEquals(2700, Player::find($ids[6])->outstanding_debt);

        // أيوب's previous debt; عبد الرحمن's 10 % discount.
        $this->assertTrue(PlayerSubscription::where('player_id', $ids[5])->where('is_legacy', true)->where('amount_owed', 2000)->exists());
        $discounted = $this->obligation($ids[6], Demo::ANNUAL);
        $this->assertSame('percent', $discounted->discount_type);
        $this->assertEquals(10, $discounted->discount_value);

        // زكرياء owes the trip only (1500); the annual is still for the assign-players article to add.
        $this->assertEquals(1500, $this->obligation($ids[7], Demo::TRIP)->remaining_amount);
        $this->assertFalse(PlayerSubscription::where('player_id', $ids[7])->whereHas('subscription', fn ($q) => $q->where('name', Demo::ANNUAL))->exists());
        $this->assertEquals(1500, Player::find($ids[7])->outstanding_debt);
    }
}
