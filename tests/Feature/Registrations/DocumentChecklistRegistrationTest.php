<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocumentBranchFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentChecklistRegistrationTest extends TestCase
{
    use DocumentBranchFixtures, RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        // One required type keeps the counts readable.
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true]);
        $this->requireDocumentsEverywhere();
    }

    private function received(Player $player, ?Branch $branch, ?string $validUntil = null, ?DocumentType $type = null): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $branch === null ? null : $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => ($type ?? $this->medical)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => now()->subMonth()->toDateString(),
            'valid_until' => $validUntil,
        ]);
    }

    private function checklist(Player $player, ?Branch $branch = null): array
    {
        $registration = $branch === null ? null : $player->registrations()->where('branch_id', $branch->id)->firstOrFail();

        return DocumentChecklist::for($player, null, $registration);
    }

    private function missingSql(Player $player, ?Branch $branch = null): int
    {
        [$sql, $bindings] = DocumentChecklist::missingCountSql(null, null, $branch?->id);

        return (int) Player::query()->whereKey($player->id)->selectRaw("{$sql} as n", $bindings)->toBase()->first()->n;
    }

    /** @param array{0: string, 1: array<int, mixed>} $term */
    private function term(Player $player, array $term): int
    {
        return (int) Player::query()->whereKey($player->id)->selectRaw("{$term[0]} as n", $term[1])->toBase()->first()->n;
    }

    private function expiringSql(Player $player, ?Branch $branch = null): bool
    {
        [$sql, $bindings] = DocumentChecklist::expiringSoonSql(null, $branch?->id);

        return Player::query()->whereKey($player->id)->whereRaw($sql, $bindings)->exists();
    }

    #[Test]
    public function a_document_settles_only_its_own_registration(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $this->assertSame(0, $this->checklist($player, $this->foot)['missing_count']);
        $this->assertSame(1, $this->checklist($player, $this->swim)['missing_count']);
        $this->assertSame(0, $this->missingSql($player, $this->foot));
        $this->assertSame(1, $this->missingSql($player, $this->swim));
        $this->assertSame(1, $this->missingSql($player));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingTypeSql($this->medical)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingTypeSql($this->medical, null, $this->foot->id)));
    }

    #[Test]
    public function club_wide_count_adds_up_the_active_registrations(): void
    {
        $player = $this->bothActive();

        $this->assertSame(2, $this->missingSql($player));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingTypeSql($this->medical)));
        $this->assertSame(2, $this->term($player, DocumentChecklist::missingRowsSql($this->medical)));
        $this->assertSame(2, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
    }

    #[Test]
    public function a_left_registration_owes_nothing(): void
    {
        $player = $this->leftFootball();

        $list = $this->checklist($player, $this->foot);
        $this->assertTrue($list['left']);
        $this->assertSame(0, $list['missing_count']);
        $this->assertSame(DocumentChecklist::NOT_REQUIRED, $list['items'][0]['state']);
        $this->assertSame('left', $list['items'][0]['reason']);

        $this->assertSame(0, $this->missingSql($player, $this->foot));
        $this->assertSame(1, $this->missingSql($player, $this->swim));
        $this->assertSame(1, $this->missingSql($player));
    }

    #[Test]
    public function left_everywhere_owes_nothing_anywhere(): void
    {
        $player = $this->leftBoth();

        $this->assertSame(0, $this->missingSql($player));
        $this->assertSame(0, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
        $this->assertSame(0, DocumentChecklist::for($player)['missing_count']);
    }

    #[Test]
    public function an_expired_paper_on_a_left_registration_is_shown_but_not_counted(): void
    {
        $player = $this->leftFootball();
        $this->received($player, $this->foot, now()->subDay()->toDateString());

        $list = $this->checklist($player, $this->foot);

        $this->assertSame(DocumentChecklist::EXPIRED, $list['items'][0]['state']);
        $this->assertFalse($list['items'][0]['counts_missing']);
        $this->assertSame(0, $list['missing_count']);
    }

    #[Test]
    public function a_player_with_no_registration_uses_their_own_rows_even_with_a_branch_given(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame(1, $this->checklist($flat)['missing_count']);
        $this->assertSame(1, $this->missingSql($flat));
        $this->assertSame(1, $this->missingSql($flat, $this->foot));

        $this->received($flat, null);

        $this->assertSame(0, $this->checklist($flat)['missing_count']);
        $this->assertSame(0, $this->missingSql($flat));
        $this->assertSame(0, $this->missingSql($flat, $this->foot));
    }

    #[Test]
    public function one_picture_settles_photo_on_every_registration(): void
    {
        $photo = DocumentType::where('code', DocumentType::PHOTO)->firstOrFail();
        $photo->update(['is_active' => true]);
        $this->medical->update(['is_active' => false]);
        $player = $this->bothActive();

        $this->assertSame(2, $this->missingSql($player));

        Player::query()->whereKey($player->id)->update(['picture_url' => '/media/players/1.jpg']);
        $player->refresh();

        foreach ([$this->foot, $this->swim] as $branch) {
            $this->assertSame(DocumentChecklist::RECEIVED_SCANNED, $this->checklist($player, $branch)['items'][0]['state']);
            $this->assertSame(0, $this->missingSql($player, $branch));
        }
        $this->assertSame(0, $this->missingSql($player));
    }

    #[Test]
    public function without_a_picture_a_paper_photo_settles_only_its_registration(): void
    {
        $photo = DocumentType::where('code', DocumentType::PHOTO)->firstOrFail();
        $photo->update(['is_active' => true]);
        $this->medical->update(['is_active' => false]);
        $player = $this->bothActive();
        $this->received($player, $this->foot, null, $photo);

        $this->assertSame(0, $this->missingSql($player, $this->foot));
        $this->assertSame(1, $this->missingSql($player, $this->swim));
    }

    #[Test]
    public function an_exemption_holds_on_its_registration_only(): void
    {
        $player = $this->bothActive();
        PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $this->foot->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => 'Doctor',
        ]);

        $this->assertSame(0, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical, null, $this->foot->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical, null, $this->swim->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
    }

    #[Test]
    public function expiring_soon_is_per_registration_and_ignores_a_left_one(): void
    {
        $soon = now()->addDays(10)->toDateString();

        $both = $this->bothActive();
        $this->received($both, $this->swim, $soon);
        $this->assertTrue($this->expiringSql($both));
        $this->assertTrue($this->expiringSql($both, $this->swim));
        $this->assertFalse($this->expiringSql($both, $this->foot));
        $this->assertSame(1, $this->checklist($both, $this->swim)['expiring_count']);
        $this->assertSame(1, $this->term($both, DocumentChecklist::expiringTypeSql($this->medical)));

        $left = $this->leftFootball();
        $this->received($left, $this->foot, $soon);
        $this->assertFalse($this->expiringSql($left));
        $this->assertSame(0, $this->checklist($left, $this->foot)['expiring_count']);
        $this->assertSame(0, $this->term($left, DocumentChecklist::expiringTypeSql($this->medical)));

        $flat = $this->noRegistration();
        $this->received($flat, null, $soon);
        $this->assertTrue($this->expiringSql($flat));
    }

    #[Test]
    public function the_page_gets_one_tab_per_registration_with_the_main_one_first(): void
    {
        DB::table('website_configs')->update(['main_branch_id' => $this->swim->id]);
        $player = $this->leftFootball();

        $page = DocumentChecklist::forPlayer($player);

        $this->assertSame([$this->swim->id, $this->foot->id], array_column($page['tabs'], 'branch_id'));
        $this->assertSame(['Swimming', 'Football'], array_column($page['tabs'], 'branch_name'));
        $this->assertSame([false, true], array_column($page['tabs'], 'left'));
        $this->assertSame($page['tabs'][0]['registration_id'], $page['registration_id']);
        $this->assertSame($page['tabs'][0]['items'], $page['items']);
    }

    #[Test]
    public function a_player_with_no_registration_has_no_tabs(): void
    {
        $page = DocumentChecklist::forPlayer($this->noRegistration());

        $this->assertSame([], $page['tabs']);
        $this->assertNull($page['registration_id']);
        $this->assertFalse($page['left']);
        $this->assertSame(1, $page['missing_count']);
    }

    #[Test]
    public function an_inactive_type_shows_only_on_the_registration_that_holds_it(): void
    {
        $old = DocumentType::create(['code' => 'old', 'name' => 'Old', 'validity' => 'none', 'is_required' => true, 'is_active' => false]);
        $player = $this->bothActive();
        $this->received($player, $this->swim, null, $old);

        $this->assertCount(1, $this->checklist($player, $this->foot)['items']);
        $this->assertCount(2, $this->checklist($player, $this->swim)['items']);
    }

    #[Test]
    public function the_sql_runs_when_the_left_status_is_missing(): void
    {
        $player = $this->bothActive();
        DB::table('player_statuses')->where('code', 'left')->delete();

        $this->assertSame(2, $this->missingSql($player));
    }

    #[Test]
    public function an_unlinked_paper_of_a_registered_player_does_not_count_as_expiring(): void
    {
        $player = $this->bothActive();
        $document = $this->received($player, $this->swim, now()->addDays(10)->toDateString());
        $this->assertTrue($this->expiringSql($player, $this->swim));

        // The registration goes without the service: the foreign key unlinks the paper.
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->delete();
        $this->assertNull($document->fresh()->branch_registration_id);

        $this->assertFalse($this->expiringSql($player));
        $this->assertFalse($this->expiringSql($player, $this->foot));
        $this->assertFalse($this->expiringSql($player, $this->swim));
        $this->assertSame(0, DocumentChecklist::for($player)['expiring_count']);
    }

    #[Test]
    public function a_registration_of_another_player_is_refused(): void
    {
        $player = $this->bothActive();
        $other = $this->leftFootball();

        $this->expectException(\InvalidArgumentException::class);

        DocumentChecklist::for($player, null, $other->registrations()->firstOrFail());
    }
}
