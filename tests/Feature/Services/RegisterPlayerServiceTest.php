<?php

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegisterPlayerServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_player_without_attaching_any_subscription(): void
    {
        $category = Category::query()->create([
            'name' => 'U17',
        ]);

        // A mandatory, active subscription for the player's category: owner
        // decision — registration still attaches nothing.
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
        $this->assertSame(
            (int) now()->year.'00001',
            $player->membership_id,
            'first player of the year gets sequence 00001',
        );
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('player_subscriptions', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0.0, (float) $player->fresh()->outstanding_debt);
    }
}
