<?php

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegisterPlayerServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_player_and_assigns_mandatory_subscription_for_matching_category(): void
    {
        $category = Category::query()->create([
            'name' => 'U17',
        ]);

        $subscription = Subscription::query()->create([
            'name' => 'Annual Membership',
            'year' => (int) now()->year,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        $subscription->categories()->attach($category->id);

        $service = new RegisterPlayerService;

        $player = $service->handle([
            'firstname' => 'Ali',
            'lastname' => 'Brahimi',
            'category_id' => $category->id,
            'is_student' => true,
            'join_year' => (int) now()->year,
        ]);

        $this->assertInstanceOf(Player::class, $player);
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('player_subscriptions', 1);
        $this->assertDatabaseCount('transactions', 0);

        $playerSubscription = PlayerSubscription::query()->firstOrFail();

        $this->assertSame((float) $subscription->amount_student, (float) $playerSubscription->amount_owed);
        $this->assertTrue((bool) $playerSubscription->is_mandatory);
        $this->assertSame(2000.0, (float) $player->fresh()->outstanding_debt);
    }
}
