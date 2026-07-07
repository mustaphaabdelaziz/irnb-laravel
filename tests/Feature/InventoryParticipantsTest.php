<?php

namespace Tests\Feature;

use App\Models\InventorySession;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function makeSession(string $status = 'in_progress'): InventorySession
    {
        return InventorySession::create([
            'reference' => 'INV-T-'.uniqid(),
            'type' => 'ad_hoc',
            'session_date' => '2026-05-01',
            'status' => $status,
        ]);
    }

    #[Test]
    public function it_syncs_a_mixed_set_of_user_and_player_participants(): void
    {
        $session = $this->makeSession();
        $staff = User::factory()->create();
        $player = Player::create(['membership_id' => 'M-0001', 'firstname' => 'Ali']);

        $this->actingAs($this->user())
            ->post(route('inventory.participants', $session), [
                'participants' => [
                    ['type' => 'User', 'id' => $staff->id],
                    ['type' => 'Player', 'id' => $player->id],
                ],
            ])->assertRedirect();

        $this->assertSame(2, $session->participants()->count());
        $this->assertDatabaseHas('inventory_session_participants', [
            'inventory_session_id' => $session->id,
            'participant_type' => User::class,
            'participant_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('inventory_session_participants', [
            'inventory_session_id' => $session->id,
            'participant_type' => Player::class,
            'participant_id' => $player->id,
        ]);
    }

    #[Test]
    public function reposting_replaces_the_participant_set(): void
    {
        $session = $this->makeSession();
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($this->user())->post(route('inventory.participants', $session), [
            'participants' => [['type' => 'User', 'id' => $a->id], ['type' => 'User', 'id' => $b->id]],
        ]);
        $this->assertSame(2, $session->participants()->count());

        $this->actingAs($this->user())->post(route('inventory.participants', $session), [
            'participants' => [['type' => 'User', 'id' => $a->id]],
        ]);
        $this->assertSame(1, $session->fresh()->participants()->count());
    }

    #[Test]
    public function participants_cannot_be_set_on_a_completed_session(): void
    {
        $session = $this->makeSession('completed');
        $staff = User::factory()->create();

        $this->actingAs($this->user())
            ->post(route('inventory.participants', $session), [
                'participants' => [['type' => 'User', 'id' => $staff->id]],
            ])->assertForbidden();

        $this->assertSame(0, $session->participants()->count());
    }
}
