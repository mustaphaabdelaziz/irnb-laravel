<?php

namespace Tests\Unit\Finance;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Finance\PlayerPaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerPaymentRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        $category = Category::create(['name' => 'U15']);

        return Player::create([
            'membership_id' => '8000000001', 'firstname' => 'P', 'lastname' => 'Test',
            'category_id' => $category->id, 'outstanding_debt' => 0,
        ]);
    }

    private function subscription(Player $player, float $amount): PlayerSubscription
    {
        $catalog = Subscription::create([
            'name' => 'S'.uniqid(), 'year' => 2026, 'amount_student' => $amount, 'amount_worker' => $amount,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $catalog->categories()->attach($player->category_id);

        return $catalog->assignTo($player);
    }

    #[Test]
    public function an_overpayment_splits_into_payment_and_donation(): void
    {
        $player = $this->player();
        $sub = $this->subscription($player, 3000);

        $created = app(PlayerPaymentRecorder::class)->subscription($sub, $player, 3500, 'cash', null, null, null);

        $this->assertCount(2, $created);
        $this->assertSame('subscription', $created[0]->category);
        $this->assertEquals(3000, $created[0]->amount);
        $this->assertSame('donation', $created[1]->category);
        $this->assertEquals(500, $created[1]->amount);
    }

    #[Test]
    public function a_group_payment_pays_in_order_and_shares_one_group(): void
    {
        $player = $this->player();
        $first = $this->subscription($player, 3000);
        $second = $this->subscription($player, 2000);

        $created = app(PlayerPaymentRecorder::class)->group(collect([$first, $second]), $player, 4000, 'cash', null, null, null);

        $this->assertCount(2, $created);
        $groups = Transaction::whereKey(collect($created)->pluck('id'))->pluck('payment_group')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());
        $this->assertEquals(3000, $created[0]->amount);
        $this->assertEquals(1000, $created[1]->amount);
    }
}
