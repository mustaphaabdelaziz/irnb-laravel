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

/**
 * A season's subscription lists every assignee of the club; sending whole
 * player models (contact, health…) made the page seconds slow and megabytes
 * big. Each row carries only what the table shows.
 */
class SubscriptionShowPayloadTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function player(string $lastname, ?Category $category = null): Player
    {
        return Player::create([
            'membership_id' => '83'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Ali', 'lastname' => $lastname,
            'is_student' => true, 'outstanding_debt' => 0,
            'category_id' => $category?->id,
            'email' => 'private@example.com', 'health_medical_conditions' => 'asthma',
        ]);
    }

    private function obligation(Player $player, Subscription $sub, array $attributes): PlayerSubscription
    {
        return PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $sub->id,
            'year' => $sub->year, 'status_at_time' => 'student', 'is_mandatory' => false,
            ...$attributes,
        ]);
    }

    #[Test]
    public function each_row_carries_the_table_fields_and_no_private_player_data(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $sub = Subscription::create([
            'name' => 'Season card', 'year' => (int) now()->year,
            'amount_student' => 1000, 'amount_worker' => 1000, 'is_mandatory' => false, 'is_active' => true,
        ]);
        $this->obligation($this->player('Zitouni', $category), $sub, ['amount_owed' => 1000, 'amount_paid' => 400]);
        $this->obligation($this->player('Amrani', $category), $sub, ['amount_owed' => 1000, 'amount_paid' => 0, 'discount_type' => 'percent', 'discount_value' => 100]);
        $this->obligation($this->player('Mansouri'), $sub, ['amount_owed' => 1000, 'amount_paid' => 0, 'is_exempt' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('subscriptions.show', $sub))
            ->assertOk()
            ->assertInertia(function ($page) use ($category) {
                $props = $page->toArray()['props'];
                $rows = $props['playerSubscriptions'];

                $this->assertSame(['Amrani', 'Mansouri', 'Zitouni'], array_column(array_column($rows, 'player'), 'lastname'));
                $this->assertSame(['paid', 'exempt', 'partial'], array_column($rows, 'payment_status'));
                $this->assertEquals([0, 0, 600], array_column($rows, 'remaining_amount'));
                $this->assertSame('400.00', $rows[2]['amount_paid']);
                $this->assertSame(
                    ['id', 'membership_id', 'lastname', 'fullname', 'category'],
                    array_keys($rows[0]['player']),
                );
                $this->assertSame($category->id, $rows[0]['player']['category']['id']);
                $this->assertNull($rows[1]['player']['category']);
                $this->assertStringNotContainsString('asthma', json_encode($rows));
                $this->assertStringNotContainsString('private@example.com', json_encode($rows));

                $this->assertSame(3, $props['stats']['total']);
                $this->assertSame(2, $props['stats']['paid_count']);
                $this->assertSame(0, $props['stats']['unpaid_count']);
                $this->assertSame(1, $props['stats']['partial_count']);
                $this->assertEquals(400, $props['stats']['total_collected']);
            });
    }
}
