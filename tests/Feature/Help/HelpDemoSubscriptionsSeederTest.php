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

        $annual = Subscription::where('name', Demo::ANNUAL)->firstOrFail();
        $this->assertSame(Subscription::KIND_ANNUAL, $annual->kind);
        $this->assertEquals(3000, $annual->amount_student);
        $this->assertEquals(5000, $annual->amount_worker);
        $this->assertSame(Subscription::KIND_EXCEPTIONAL, Subscription::where('name', Demo::TRIP)->value('kind'));
        $this->assertNotNull(FinanceAccount::where('name', Demo::CAISSE)->value('category_id'));

        // أحمد paid the annual in full; his receipt was printed once.
        $ahmed = $this->obligation(1, Demo::ANNUAL);
        $this->assertEquals(0, $ahmed->remaining_amount);
        $paid = Transaction::where('player_subscription_id', $ahmed->id)->firstOrFail();
        $this->assertSame(1, ReceiptPrint::where('transaction_id', $paid->id)->count());

        // ياسين: one grouped payment covering two subscriptions.
        $groups = Transaction::where('related_entity_id', 2)->where('category', 'subscription')->pluck('payment_group')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());

        // أمين: partial; رياض: a donation exists; إسلام: unpaid.
        $this->assertEquals(1000, $this->obligation(3, Demo::ANNUAL)->amount_paid);
        $this->assertTrue(Transaction::where('related_entity_id', 4)->where('category', 'donation')->exists());
        $this->assertEquals(0, $this->obligation(5, Demo::ANNUAL)->amount_paid);
        $this->assertGreaterThan(0, Player::find(5)->outstanding_debt);

        // أيوب's previous debt; عبد الرحمن's 10 % discount.
        $this->assertTrue(PlayerSubscription::where('player_id', 6)->where('is_legacy', true)->where('amount_owed', 2000)->exists());
        $discounted = $this->obligation(7, Demo::ANNUAL);
        $this->assertSame('percent', $discounted->discount_type);
        $this->assertEquals(10, $discounted->discount_value);
    }
}
