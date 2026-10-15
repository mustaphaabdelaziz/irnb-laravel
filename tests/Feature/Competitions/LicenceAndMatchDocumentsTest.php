<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class LicenceAndMatchDocumentsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function the_licence_number_is_saved_from_the_player_form(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname, 'lastname' => $player->lastname, 'licence_number' => 'DZ-FAF-12345',
        ])->assertSessionHasNoErrors();

        $this->assertSame('DZ-FAF-12345', $player->fresh()->licence_number);
    }

    #[Test]
    public function a_document_type_can_be_required_for_matches(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), [
            'name' => 'Medical certificate', 'validity' => 'season', 'is_required' => false,
            'required_for_matches' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(DocumentType::where('name', 'Medical certificate')->sole()->required_for_matches);
    }

    #[Test]
    public function the_per_type_missing_sql_matches_received_and_missing_players(): void
    {
        $type = DocumentType::create(['code' => 'medical', 'name' => 'Medical', 'validity' => 'none', 'is_required' => false, 'required_for_matches' => true, 'is_active' => true]);
        $category = $this->category();
        [$has, $lacks] = [$this->player($category), $this->player($category)];
        PlayerDocument::create(['player_id' => $has->id, 'document_type_id' => $type->id, 'state' => 'received', 'received_at' => now()->toDateString()]);

        [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
        $missing = Player::query()->whereIn('id', [$has->id, $lacks->id])->select('id')->selectRaw("{$sql} as missing", $bindings)
            ->pluck('missing', 'id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$has->id => 0, $lacks->id => 1], $missing);
    }
}
