<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionMandatoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function storing_a_subscription_persists_the_mandatory_flag(): void
    {
        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), [
                'name' => 'Annual', 'year' => (int) now()->year,
                'amount_student' => 2000, 'amount_worker' => 3000,
                'is_mandatory' => true, 'category_ids' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('subscriptions', ['name' => 'Annual', 'is_mandatory' => true]);
    }

    #[Test]
    public function storing_an_optional_subscription_persists_it_as_not_mandatory(): void
    {
        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), [
                'name' => 'Camp', 'year' => (int) now()->year,
                'amount_student' => 1500, 'amount_worker' => 1500,
                'is_mandatory' => false, 'category_ids' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('subscriptions', ['name' => 'Camp', 'is_mandatory' => false]);
    }
}
