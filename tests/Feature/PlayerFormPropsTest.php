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

    #[Test]
    public function storing_a_player_persists_geo_and_club_fields(): void
    {
        $this->actingAs($this->admin())
            ->post(route('players.store'), [
                'firstname' => 'Yacine',
                'state' => 'Adrar',
                'city' => 'Reggane',
                'is_student' => false,
                'status_value' => 'منخرط',
                'join_year' => 2026,
                'team' => 'A',
                'skill_level' => 7,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('players', [
            'firstname' => 'Yacine', 'state' => 'Adrar', 'city' => 'Reggane',
            'join_year' => 2026, 'team' => 'A', 'skill_level' => 7, 'is_student' => false,
            'status_value' => 'منخرط',
        ]);
    }
}
