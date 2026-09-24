<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentChecklistTest extends TestCase
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
            'membership_id' => '2026'.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT),
            'firstname' => 'Player'.$this->sequence,
            'lastname' => 'Test',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function received(Player $player, string $code, ?string $validUntil = null, int $files = 0): PlayerDocument
    {
        $document = PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);

        for ($i = 1; $i <= $files; $i++) {
            $document->files()->create([
                'path' => "player-documents/{$player->id}/scan-{$i}.pdf",
                'original_name' => "scan-{$i}.pdf",
                'mime' => 'application/pdf',
                'size' => 2048,
            ]);
        }

        return $document;
    }

    private function exempt(Player $player, string $code): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => 'Handled by the federation',
        ]);
    }

    /** @return array<string, string> code => state */
    private function states(Player $player): array
    {
        return collect(DocumentChecklist::for($player->fresh())['items'])
            ->mapWithKeys(fn (array $item) => [$item['type']['code'] => $item['state']])
            ->all();
    }

    private function item(Player $player, string $code): ?array
    {
        return collect(DocumentChecklist::for($player->fresh())['items'])
            ->first(fn (array $item) => $item['type']['code'] === $code);
    }

    private function missing(Player $player): int
    {
        return DocumentChecklist::for($player->fresh())['missing_count'];
    }

    #[Test]
    public function an_adult_with_nothing_is_missing_the_three_documents_every_player_needs(): void
    {
        $player = $this->player();

        $this->assertSame([
            'birth_certificate' => 'missing',
            'photo' => 'missing',
            'medical_certificate' => 'missing',
            'parental_authorization' => 'not_required',
            'id_card_copy' => 'not_required',
            'residence_certificate' => 'not_required',
            'school_certificate' => 'not_required',
        ], $this->states($player));
        $this->assertSame(3, $this->missing($player));
        $this->assertSame('age', $this->item($player, 'parental_authorization')['reason']);
        $this->assertSame('optional', $this->item($player, 'id_card_copy')['reason']);
    }

    #[Test]
    public function a_minor_also_needs_the_parental_authorization(): void
    {
        $player = $this->player(['birthdate' => '2012-03-01']);

        $this->assertSame('missing', $this->states($player)['parental_authorization']);
        $this->assertSame(4, $this->missing($player));
    }

    #[Test]
    public function a_player_without_a_birth_date_is_not_age_limited(): void
    {
        $player = $this->player(['birthdate' => null]);

        $this->assertSame('missing', $this->states($player)['parental_authorization']);
        $this->assertSame(4, $this->missing($player));
    }

    #[Test]
    public function the_age_limit_ends_on_the_eighteenth_birthday(): void
    {
        // Today is 2026-10-05.
        $seventeen = $this->player(['birthdate' => '2008-10-06']); // 18 tomorrow
        $eighteen = $this->player(['birthdate' => '2008-10-05']);  // 18 today

        $this->assertSame('missing', $this->states($seventeen)['parental_authorization']);
        $this->assertSame('not_required', $this->states($eighteen)['parental_authorization']);
    }

    #[Test]
    public function the_photo_is_the_profile_picture(): void
    {
        $player = $this->player(['picture_url' => '/media/players/amine.jpg']);

        $this->assertSame('received_scanned', $this->states($player)['photo']);
        $this->assertTrue($this->item($player, 'photo')['type']['is_photo']);
        $this->assertSame(2, $this->missing($player));
    }

    #[Test]
    public function a_received_document_is_scanned_or_on_paper(): void
    {
        $player = $this->player();
        $this->received($player, 'birth_certificate', null, 2);
        $this->received($player, 'residence_certificate');

        $this->assertSame('received_scanned', $this->states($player)['birth_certificate']);
        $this->assertSame('received_paper', $this->states($player)['residence_certificate']);

        $document = $this->item($player, 'birth_certificate')['document'];
        $this->assertSame('2026-09-01', $document['received_at']);
        $this->assertSame(['scan-1.pdf', 'scan-2.pdf'], array_column($document['files'], 'original_name'));
        $this->assertArrayNotHasKey('path', $document['files'][0]);
    }

    #[Test]
    public function a_document_expiring_within_thirty_days_is_flagged_but_not_missing(): void
    {
        $soon = $this->player();
        $this->received($soon, 'medical_certificate', '2026-11-04'); // today + 30

        $later = $this->player();
        $this->received($later, 'medical_certificate', '2026-11-05'); // today + 31

        $this->assertSame('expires_soon', $this->states($soon)['medical_certificate']);
        $this->assertSame(2, $this->missing($soon));
        $this->assertSame(1, DocumentChecklist::for($soon->fresh())['expiring_count']);

        $this->assertSame('received_paper', $this->states($later)['medical_certificate']);
        $this->assertSame(0, DocumentChecklist::for($later->fresh())['expiring_count']);
    }

    #[Test]
    public function a_document_valid_until_today_is_still_valid(): void
    {
        $player = $this->player();
        $this->received($player, 'medical_certificate', '2026-10-05');

        $this->assertSame('expires_soon', $this->states($player)['medical_certificate']);
    }

    #[Test]
    public function an_expired_required_document_counts_as_missing(): void
    {
        $player = $this->player();
        $this->received($player, 'medical_certificate', '2026-10-04');

        $item = $this->item($player, 'medical_certificate');
        $this->assertSame('expired', $item['state']);
        $this->assertTrue($item['counts_missing']);
        $this->assertSame(3, $this->missing($player));
    }

    #[Test]
    public function an_expired_optional_document_is_shown_but_not_counted(): void
    {
        $player = $this->player();
        $this->received($player, 'school_certificate', '2026-08-31');

        $item = $this->item($player, 'school_certificate');
        $this->assertSame('expired', $item['state']);
        $this->assertFalse($item['counts_missing']);
        $this->assertSame(3, $this->missing($player));
    }

    #[Test]
    public function an_exempted_document_is_not_required(): void
    {
        $player = $this->player();
        $this->exempt($player, 'birth_certificate');
        $this->exempt($player, 'photo');

        $this->assertSame('not_required', $this->states($player)['birth_certificate']);
        $this->assertSame('exempt', $this->item($player, 'birth_certificate')['reason']);
        $this->assertSame('Handled by the federation', $this->item($player, 'birth_certificate')['document']['exempt_reason']);
        $this->assertSame('not_required', $this->states($player)['photo']);
        $this->assertSame(1, $this->missing($player));
    }

    #[Test]
    public function an_inactive_type_is_listed_only_where_a_record_exists_and_never_counts(): void
    {
        $without = $this->player();
        $with = $this->player();
        $this->received($with, 'medical_certificate', '2026-10-01');
        $this->type('medical_certificate')->update(['is_active' => false]);

        $this->assertArrayNotHasKey('medical_certificate', $this->states($without));
        $this->assertSame(2, $this->missing($without));

        $item = $this->item($with, 'medical_certificate');
        $this->assertFalse($item['type']['is_active']);
        $this->assertSame('expired', $item['state']);
        $this->assertFalse($item['counts_missing']);
        $this->assertSame(2, $this->missing($with));
    }

    #[Test]
    public function an_optional_or_inactive_type_never_matches_the_missing_type_filter(): void
    {
        $this->assertSame(['0', []], DocumentChecklist::missingCountSql(null, $this->type('school_certificate')->id));

        $this->type('medical_certificate')->update(['is_active' => false]);
        $this->assertSame(['0', []], DocumentChecklist::missingCountSql(null, $this->type('medical_certificate')->id));
    }

    #[Test]
    public function the_sql_count_agrees_with_the_checklist_for_every_situation(): void
    {
        $players = $this->fixtures();

        $this->assertAgreement($players);

        // Rules change under existing records: a type is switched off, another
        // loses its age limit. Both forms must follow.
        $this->type('medical_certificate')->update(['is_active' => false]);
        $this->type('parental_authorization')->update(['max_age' => null]);

        $this->assertAgreement($players);
    }

    /** @return array<string, Player> label => player */
    private function fixtures(): array
    {
        $players = [
            'adult, nothing' => $this->player(),
            'minor, nothing' => $this->player(['birthdate' => '2012-03-01']),
            'no birth date' => $this->player(['birthdate' => null]),
            '18 tomorrow' => $this->player(['birthdate' => '2008-10-06']),
            '18 today' => $this->player(['birthdate' => '2008-10-05']),
            'picture' => $this->player(['picture_url' => '/media/players/a.jpg']),
            'empty picture string' => $this->player(['picture_url' => '']),
            'scanned' => $this->player(),
            'expires soon' => $this->player(),
            'valid today' => $this->player(),
            'expired yesterday' => $this->player(),
            'expired optional' => $this->player(),
            'exempt' => $this->player(),
            'exempt photo' => $this->player(),
            'complete adult' => $this->player(['picture_url' => '/media/players/b.jpg']),
            'complete minor' => $this->player(['birthdate' => '2013-05-05', 'picture_url' => '/media/players/c.jpg']),
            'minor, expired parental' => $this->player(['birthdate' => '2011-01-01']),
        ];

        $this->received($players['scanned'], 'birth_certificate', null, 1);
        $this->received($players['expires soon'], 'medical_certificate', '2026-11-04');
        $this->received($players['valid today'], 'medical_certificate', '2026-10-05');
        $this->received($players['expired yesterday'], 'medical_certificate', '2026-10-04');
        $this->received($players['expired optional'], 'school_certificate', '2026-08-31');
        $this->exempt($players['exempt'], 'medical_certificate');
        $this->exempt($players['exempt photo'], 'photo');

        foreach (['complete adult', 'complete minor'] as $label) {
            $this->received($players[$label], 'birth_certificate', null, 1);
            $this->received($players[$label], 'medical_certificate', '2027-08-31');
        }
        $this->received($players['complete minor'], 'parental_authorization', '2027-08-31');
        $this->received($players['minor, expired parental'], 'parental_authorization', '2026-08-31');

        return $players;
    }

    /** @param array<string, Player> $players */
    private function assertAgreement(array $players): void
    {
        [$sql, $bindings] = DocumentChecklist::missingCountSql();
        $sqlCounts = Player::query()
            ->select('players.id')
            ->selectRaw("{$sql} as missing_documents_count", $bindings)
            ->pluck('missing_documents_count', 'id')
            ->map(fn ($count) => (int) $count);

        $checklists = [];
        foreach ($players as $label => $player) {
            $checklists[$label] = DocumentChecklist::for($player->fresh());
            $this->assertSame($checklists[$label]['missing_count'], $sqlCounts[$player->id], "missing count: {$label}");
        }

        // Per type: the "missing type X" filter selects exactly the players whose
        // checklist counts that type as missing.
        $required = DocumentType::where('is_active', true)->where('is_required', true)->get();
        foreach ($required as $type) {
            [$typeSql, $typeBindings] = DocumentChecklist::missingCountSql(null, $type->id);
            $fromSql = Player::query()->whereRaw("{$typeSql} > 0", $typeBindings)->orderBy('id')->pluck('id')->all();

            $fromPhp = collect($players)
                ->filter(fn (Player $player, string $label) => collect($checklists[$label]['items'])
                    ->contains(fn (array $item) => $item['type']['id'] === $type->id && $item['counts_missing']))
                ->map(fn (Player $player) => $player->id)
                ->sort()->values()->all();

            $this->assertSame($fromPhp, $fromSql, "missing {$type->code}");
        }

        // Expiring soon.
        [$expiringSql, $expiringBindings] = DocumentChecklist::expiringSoonSql();
        $fromSql = Player::query()->whereRaw($expiringSql, $expiringBindings)->orderBy('id')->pluck('id')->all();
        $fromPhp = collect($players)
            ->filter(fn (Player $player, string $label) => $checklists[$label]['expiring_count'] > 0)
            ->map(fn (Player $player) => $player->id)
            ->sort()->values()->all();

        $this->assertSame($fromPhp, $fromSql, 'expiring soon');
    }
}
