<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFormPropsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function create_page_exposes_geo_and_sequence_props(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Players/Create')
                ->has('wilayas', 58)
                ->has('wilayas.0', fn (AssertableInertia $w) => $w->has('id')->has('name')->has('ar_name'))
                ->has('communes')
                ->where('defaultJoinYear', (int) now()->year)
                ->where('nextSequenceByYear.'.now()->year, 1));
    }
}
