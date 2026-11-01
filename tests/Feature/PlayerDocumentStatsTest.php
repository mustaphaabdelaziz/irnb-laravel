<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20265'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Listed'.$this->sequence,
            'lastname' => 'Strip',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function receive(Player $player, string $code, ?string $validUntil = null): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => DocumentType::where('code', $code)->value('id'),
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function props(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    #[Test]
    public function the_strip_counts_every_player_the_list_shows_and_follows_its_filters(): void
    {
        $seniors = Category::create(['name' => 'Seniors']);
        $juniors = Category::create(['name' => 'Juniors']);
        $done = $this->player(['category_id' => $seniors->id, 'picture_url' => '/media/players/done.jpg']);
        $this->receive($done, 'birth_certificate');
        $this->receive($done, 'medical_certificate', '2027-08-31');
        $this->player(['category_id' => $juniors->id]);
        $this->player(['category_id' => $juniors->id, 'archived' => true]);   // not in the list
        $this->player(['status_id' => Player::leftStatusId()]);              // hidden by default
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $all = $this->props($admin)['documentStats'];
        $this->assertSame(2, $all['players_total']);
        $this->assertSame(1, $all['players_complete']);
        $this->assertSame(6, $all['required']);
        $this->assertSame(3, $all['received']);
        $this->assertSame(3, $all['missing']);

        $seniorsOnly = $this->props($admin, ['category_id' => [$seniors->id]])['documentStats'];
        $this->assertSame(1, $seniorsOnly['players_total']);
        $this->assertEquals(100, $seniorsOnly['pct']);

        $missing = $this->props($admin, ['documents' => ['missing']])['documentStats'];
        $this->assertSame(1, $missing['players_total']);
        $this->assertSame(0, $missing['players_complete']);

        // Every page, not the 25 rows on screen.
        foreach (range(1, 30) as $i) {
            $this->player();
        }
        $this->assertSame(32, $this->props($admin)['documentStats']['players_total']);
    }

    #[Test]
    public function without_documents_view_the_strip_is_not_sent(): void
    {
        $this->player();

        $this->assertNull($this->props($this->userWith(['players' => ['view']]))['documentStats']);

        $reader = $this->userWith(['players' => ['view'], 'documents' => ['view']]);
        $this->assertSame(1, $this->props($reader)['documentStats']['players_total']);
    }
}
