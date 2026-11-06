<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Player\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentRegistrationMigrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private const MIGRATION = 'migrations/2026_11_05_500001_add_registration_to_player_documents.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function typeId(string $code = 'birth_certificate'): int
    {
        return DocumentType::where('code', $code)->value('id');
    }

    private function row(Player $player, ?int $registrationId, string $code = 'birth_certificate'): int
    {
        return DB::table('player_documents')->insertGetId([
            'player_id' => $player->id,
            'branch_registration_id' => $registrationId,
            'document_type_id' => $this->typeId($code),
            'state' => 'received',
            'received_at' => '2026-09-01',
        ]);
    }

    private function runUp(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    #[Test]
    public function existing_rows_go_to_the_main_registration_and_it_can_run_twice(): void
    {
        $both = $this->bothActive();
        $leftMain = $this->leftFootball();
        $flat = $this->noRegistration();
        $ids = [$this->row($both, null), $this->row($leftMain, null), $this->row($flat, null)];

        $this->runUp();
        $this->runUp();

        $service = app(RegistrationService::class);
        $this->assertSame($service->primary($both)->id, (int) DB::table('player_documents')->where('id', $ids[0])->value('branch_registration_id'));
        $this->assertSame($service->primary($leftMain)->id, (int) DB::table('player_documents')->where('id', $ids[1])->value('branch_registration_id'));
        $this->assertSame($this->swim->id, $service->primary($leftMain)->branch_id);
        $this->assertNull(DB::table('player_documents')->where('id', $ids[2])->value('branch_registration_id'));
    }

    #[Test]
    public function a_document_cannot_name_a_registration_that_does_not_exist(): void
    {
        $this->assertTrue(collect(Schema::getForeignKeys('player_documents'))->contains(fn (array $key) => $key['columns'] === ['branch_registration_id']));

        $this->expectException(QueryException::class);
        $this->row($this->bothActive(), 999999);
    }

    #[Test]
    public function removing_a_registration_keeps_the_document_and_its_files(): void
    {
        $player = $this->bothActive();
        $registration = $player->registrationIn($this->foot->id)->id;
        $document = $this->row($player, $registration);
        DB::table('player_document_files')->insert([
            'player_document_id' => $document, 'path' => 'a', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1,
        ]);

        DB::table('branch_player')->where('id', $registration)->delete();

        $this->assertNull(DB::table('player_documents')->where('id', $document)->value('branch_registration_id'));
        $this->assertSame(1, DB::table('player_document_files')->where('player_document_id', $document)->count());
    }

    #[Test]
    public function the_old_key_is_gone_and_the_new_one_holds(): void
    {
        $player = $this->bothActive();
        $foot = $player->registrationIn($this->foot->id)->id;
        $swim = $player->registrationIn($this->swim->id)->id;

        $this->row($player, $foot);
        $this->row($player, $swim);
        $this->assertSame(2, PlayerDocument::count());

        $this->expectException(QueryException::class);
        $this->row($player, $foot);
    }

    #[Test]
    public function rolling_back_keeps_one_row_per_type_and_every_file(): void
    {
        $player = $this->bothActive();
        $first = $this->row($player, $player->registrationIn($this->foot->id)->id);
        $second = $this->row($player, $player->registrationIn($this->swim->id)->id);
        DB::table('player_document_files')->insert([
            ['player_document_id' => $first, 'path' => 'a', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1],
            ['player_document_id' => $second, 'path' => 'b', 'original_name' => 'b.pdf', 'mime' => 'application/pdf', 'size' => 1],
        ]);

        (require database_path(self::MIGRATION))->down();

        $this->assertFalse(Schema::hasColumn('player_documents', 'branch_registration_id'));
        $this->assertSame([$first], DB::table('player_documents')->pluck('id')->all());
        $this->assertSame(2, PlayerDocumentFile::where('player_document_id', $first)->count());

        $this->runUp();
        $this->assertSame(app(RegistrationService::class)->primary($player)->id, (int) DB::table('player_documents')->value('branch_registration_id'));
    }

    #[Test]
    public function a_new_row_naming_no_registration_goes_to_the_main_one(): void
    {
        $player = $this->leftFootball();

        $document = PlayerDocument::create(['player_id' => $player->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $this->assertSame($player->registrationIn($this->swim->id)->id, $document->branch_registration_id);
        $this->assertTrue($document->registration->is($player->registrationIn($this->swim->id)));
    }

    #[Test]
    public function a_row_for_a_player_with_no_registration_stays_blank(): void
    {
        $document = PlayerDocument::create(['player_id' => $this->noRegistration()->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $this->assertNull($document->branch_registration_id);
    }

    #[Test]
    public function a_first_registration_adopts_the_rows_with_none_and_a_second_does_not(): void
    {
        $player = $this->noRegistration();
        $document = PlayerDocument::create(['player_id' => $player->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $first = app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);
        $this->assertSame($first->id, $document->fresh()->branch_registration_id);

        app(RegistrationService::class)->register($player, ['branch_id' => $this->swim->id]);
        $this->assertSame($first->id, $document->fresh()->branch_registration_id);
    }

    #[Test]
    public function a_bare_attach_adopts_them_too(): void
    {
        $player = $this->noRegistration();
        $document = PlayerDocument::create(['player_id' => $player->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $player->branches()->attach($this->foot->id);

        $this->assertSame($player->registrations()->value('id'), $document->fresh()->branch_registration_id);
    }

    #[Test]
    public function a_registration_lists_its_documents(): void
    {
        $player = $this->bothActive();
        $this->row($player, $player->registrationIn($this->swim->id)->id);

        $this->assertCount(1, $player->registrationIn($this->swim->id)->documents);
        $this->assertCount(0, $player->registrationIn($this->foot->id)->documents);
    }
}
