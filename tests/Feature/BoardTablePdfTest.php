<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTablePdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $firstname, string $lastname, ?Category $category): Player
    {
        return app(RegisterPlayerService::class)->handle([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'join_year' => 2026,
            'category_id' => $category?->id,
        ]);
    }

    #[Test]
    public function the_board_table_renders_for_one_category(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $this->player('Amine', 'Benali', $cadets);

        $response = $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_lists_only_that_categorys_active_players_in_name_order(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $juniors = Category::create(['name' => 'Juniors']);

        $this->player('Yanis', 'Ziani', $cadets);
        $this->player('Amine', 'Benali', $cadets);
        $this->player('Sami', 'Kaci', $juniors);
        $archived = $this->player('Old', 'Member', $cadets);
        $archived->forceFill(['archived' => true])->save();

        $html = view('pdf.board-table', [
            'club' => ['name' => 'IRNB', 'logo' => null, 'address' => null, 'phone' => null, 'email' => null, 'currency' => 'DZD'],
            'category' => $cadets,
            'season' => Season::current(),
            'players' => Player::query()->where('category_id', $cadets->id)->where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')->get(),
        ])->render();

        $this->assertStringContainsString('Benali', $html);
        $this->assertStringContainsString('Ziani', $html);
        $this->assertStringNotContainsString('Kaci', $html);
        $this->assertStringNotContainsString('Member', $html);
        $this->assertLessThan(strpos($html, 'Ziani'), strpos($html, 'Benali'), 'sorted by last name');
        $this->assertStringContainsString(Season::current()->label(), $html);
    }

    #[Test]
    public function an_unknown_category_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function printing_the_board_table_needs_only_players_view(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk();
    }
}
