<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_dashboard_with_stats_and_charts(): void
    {
        $admin = User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $category = Category::create(['name' => 'Senior']);
        $subscription = Subscription::create([
            'name' => 'Annual',
            'year' => (int) now()->year,
            'amount_student' => 1000,
            'amount_worker' => 1500,
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

        Transaction::create([
            'amount' => 500,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => 'donation',
            'status' => 'Paid',
            'fiscal_year' => now()->year,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('stats.totalPlayers', 1)
                ->where('stats.totalDonations', 500)
                ->has('stats.netBalance')
                ->where('stats.financeStatus', 'profit')
                ->has('charts.categoryDistribution', 1)
                ->has('charts.monthlyRevenue', 12)
                ->has('charts.subscriptionSummary', 1));
    }
}
