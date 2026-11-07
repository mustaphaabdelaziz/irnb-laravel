<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BranchDocumentTypesTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true]);
        // Football requires it, Swimming does not.
        $this->medical->branches()->sync([$this->foot->id]);
    }

    private function received(Player $player, Branch $branch, array $attributes = []): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => now()->subMonth()->toDateString(),
            ...$attributes,
        ]);
    }

    private function checklist(Player $player, Branch $branch): array
    {
        return DocumentChecklist::for($player, null, $player->registrations()->where('branch_id', $branch->id)->firstOrFail());
    }

    /** @param array{0: string, 1: array<int, mixed>} $term */
    private function term(Player $player, array $term): int
    {
        return (int) Player::query()->whereKey($player->id)->selectRaw("{$term[0]} as n", $term[1])->toBase()->first()->n;
    }

    private function expiring(Player $player, ?Branch $branch = null): bool
    {
        [$sql, $bindings] = DocumentChecklist::expiringSoonSql(null, $branch?->id);

        return Player::query()->whereKey($player->id)->whereRaw($sql, $bindings)->exists();
    }

    #[Test]
    public function only_the_ticked_branch_owes_the_type(): void
    {
        $player = $this->bothActive();

        $foot = $this->checklist($player, $this->foot);
        $swim = $this->checklist($player, $this->swim);
        $this->assertSame([$this->medical->id], array_column(array_column($foot['items'], 'type'), 'id'));
        $this->assertSame(1, $foot['missing_count']);
        $this->assertSame([], $swim['items']);
        $this->assertSame(0, $swim['missing_count']);

        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->foot->id)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->swim->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql()));
        $this->assertSame(1, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingRowsSql($this->medical)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingTypeSql($this->medical, null, $this->swim->id)));
    }

    #[Test]
    public function a_record_in_an_unticked_branch_is_retired_not_counted(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->swim, ['valid_until' => now()->addDays(10)->toDateString()]);

        $swim = $this->checklist($player, $this->swim);
        $this->assertSame([], $swim['items']);
        $this->assertCount(1, $swim['retired']);
        $this->assertSame($this->medical->id, $swim['retired'][0]['type']['id']);
        $this->assertFalse($swim['retired'][0]['counts_missing']);
        $this->assertSame(0, $swim['expiring_count']);
        $this->assertFalse($this->expiring($player, $this->swim));
        $this->assertFalse($this->expiring($player));
        $this->assertSame(0, $this->term($player, DocumentChecklist::expiringTypeSql($this->medical, null, $this->swim->id)));
    }

    #[Test]
    public function re_ticking_brings_the_record_back(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->swim);
        $this->medical->branches()->attach($this->swim->id);

        $swim = $this->checklist($player, $this->swim);
        $this->assertSame([], $swim['retired']);
        $this->assertSame(0, $swim['missing_count']);
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->swim->id)));
    }

    #[Test]
    public function a_player_with_no_registration_owes_every_active_type(): void
    {
        $player = $this->noRegistration();
        $this->medical->branches()->sync([]);

        $this->assertSame(1, DocumentChecklist::for($player)['missing_count']);
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql()));
    }

    #[Test]
    public function the_player_page_tabs_carry_their_retired_records(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->swim);

        $tabs = collect(DocumentChecklist::forPlayer($player)['tabs'])->keyBy('branch_id');
        $this->assertSame([], $tabs[$this->foot->id]['retired']);
        $this->assertCount(1, $tabs[$this->swim->id]['retired']);
    }
}
