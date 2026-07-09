<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerListActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
    }

    private function player(array $attrs = []): Player
    {
        return Player::create(array_merge([
            'firstname' => 'Test',
            'membership_id' => (string) random_int(202400001, 202499999),
            'join_year' => 2024,
            'is_student' => true,
        ], $attrs));
    }

    #[Test]
    public function destroy_archives_the_player(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->delete(route('players.destroy', $player))->assertRedirect();

        $this->assertTrue($player->fresh()->archived);
    }

    #[Test]
    public function restore_unarchives_the_player(): void
    {
        $player = $this->player(['archived' => true]);

        $this->actingAs($this->admin())->put(route('players.restore', $player))->assertRedirect();

        $this->assertFalse($player->fresh()->archived);
    }

    #[Test]
    public function force_delete_removes_the_player_but_keeps_transactions(): void
    {
        $player = $this->player(['archived' => true]);
        $tx = Transaction::create([
            'amount' => 500,
            'transaction_date' => '2026-05-01',
            'transaction_type' => 'income',
            'category' => 'subscription',
            'status' => 'Paid',
            'fiscal_year' => 2026,
            'related_entity_type' => 'Player',
            'related_entity_id' => $player->id,
            'archived' => false,
        ]);

        $this->actingAs($this->admin())->delete(route('players.forceDelete', $player))->assertRedirect();

        $this->assertDatabaseMissing('players', ['id' => $player->id]);
        $this->assertTrue($tx->fresh()->archived);
    }

    #[Test]
    public function bulk_archive_and_restore(): void
    {
        $a = $this->player();
        $b = $this->player();

        $this->actingAs($this->admin())
            ->post(route('players.bulkArchive'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();
        $this->assertTrue($a->fresh()->archived);
        $this->assertTrue($b->fresh()->archived);

        $this->actingAs($this->admin())
            ->post(route('players.bulkRestore'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();
        $this->assertFalse($a->fresh()->archived);
        $this->assertFalse($b->fresh()->archived);
    }

    #[Test]
    public function bulk_force_delete_removes_players(): void
    {
        $a = $this->player(['archived' => true]);
        $b = $this->player(['archived' => true]);

        $this->actingAs($this->admin())
            ->post(route('players.bulkForceDelete'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('players', ['id' => $a->id]);
        $this->assertDatabaseMissing('players', ['id' => $b->id]);
    }

    #[Test]
    public function export_returns_csv_with_the_player(): void
    {
        $player = $this->player(['firstname' => 'Exported', 'lastname' => 'Player']);

        $response = $this->actingAs($this->admin())->get(route('players.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($player->membership_id, $response->streamedContent());
    }

    #[Test]
    public function export_respects_the_archived_filter(): void
    {
        $active = $this->player(['membership_id' => '202400001']);
        $archived = $this->player(['membership_id' => '202400002', 'archived' => true]);

        $response = $this->actingAs($this->admin())->get(route('players.export', ['archived' => 1]));

        $content = $response->streamedContent();
        $this->assertStringContainsString('202400002', $content);
        $this->assertStringNotContainsString('202400001', $content);
    }
}
