<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use App\Services\Player\MembershipNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerMembershipIdTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'email_verified_at' => now(),
        ]);
    }

    private function makePlayer(int $joinYear): Player
    {
        return Player::create([
            'firstname' => 'Test',
            'membership_id' => MembershipNumber::format($joinYear, 1),
            'join_year' => $joinYear,
            'is_student' => true,
        ]);
    }

    #[Test]
    public function it_keeps_the_membership_id_when_the_join_year_changes(): void
    {
        // The id is printed on the member card and written on the paper folder:
        // correcting the join year must not renumber the member.
        $player = $this->makePlayer(2024);
        $original = $player->membership_id;

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), [
                'firstname' => 'Test',
                'join_year' => 2025,
            ])
            ->assertRedirect();

        $player->refresh();
        $this->assertSame($original, $player->membership_id);
        $this->assertSame(2025, $player->join_year);
    }

    #[Test]
    public function it_keeps_membership_id_when_join_year_is_unchanged(): void
    {
        $player = $this->makePlayer(2024);
        $original = $player->membership_id;

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), [
                'firstname' => 'Renamed',
                'join_year' => 2024,
            ])
            ->assertRedirect();

        $player->refresh();
        $this->assertSame($original, $player->membership_id);
        $this->assertSame('Renamed', $player->firstname);
    }
}
