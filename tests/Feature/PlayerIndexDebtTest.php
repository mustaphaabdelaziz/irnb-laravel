<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerIndexDebtTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_index_computes_outstanding_debt_per_player(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $category = Category::create(['name' => 'Senior']);
        $subscription = Subscription::create([
            'name' => 'Annual',
            'year' => (int) now()->year,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        $subscription->categories()->attach($category->id);

        app(RegisterPlayerService::class)->handle([
            'firstname' => 'Sami',
            'lastname' => 'Khelifi',
            'category_id' => $category->id,
            'is_student' => true,
        ], $admin->id);

        $this->actingAs($admin)
            ->get(route('players.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Players/Index')
                ->where('players.data.0.total_debt', 2000));
    }
}
