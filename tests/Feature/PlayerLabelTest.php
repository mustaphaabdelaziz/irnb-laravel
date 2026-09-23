<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerLabelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $firstname = 'Amine'): Player
    {
        return app(RegisterPlayerService::class)->handle(['firstname' => $firstname, 'join_year' => 2026]);
    }

    #[Test]
    public function a_label_renders_as_a_pdf(): void
    {
        $player = $this->player();

        $response = $this->actingAs($this->admin())->get(route('players.label', $player))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function a_sheet_of_labels_renders_for_a_selection(): void
    {
        $first = $this->player('Amine');
        $second = $this->player('Yanis');

        $response = $this->actingAs($this->admin())
            ->get(route('players.labels', ['ids' => $first->id.','.$second->id]))
            ->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function a_selection_that_names_no_real_player_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.labels', ['ids' => '999999']))
            ->assertSessionHasErrors('ids');
    }

    #[Test]
    public function the_label_markup_carries_the_numbers_and_the_drawer(): void
    {
        $player = $this->player();
        $player->refresh();

        $html = view('pdf.folder-label', [
            'club' => ['name' => 'IRNB', 'logo' => null, 'address' => null, 'phone' => null, 'email' => null, 'currency' => 'DZD'],
            'players' => collect([$player]),
        ])->render();

        $this->assertStringContainsString('0001', $html);
        $this->assertStringContainsString($player->membership_id, $html);
        $this->assertStringContainsString('type="QR"', $html);
    }

    #[Test]
    public function printing_labels_needs_only_players_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)->get(route('players.label', $this->player()))->assertOk();
    }
}
