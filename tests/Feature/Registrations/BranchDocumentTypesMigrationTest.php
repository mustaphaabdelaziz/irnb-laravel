<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BranchDocumentTypesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PIVOT = 'migrations/2026_11_07_100001_create_branch_document_type.php';

    #[Test]
    public function the_backfill_ticks_every_branch_on_every_type_once(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        DB::table('branch_document_type')->delete();

        $migration = require database_path(self::PIVOT);
        $migration->up();
        $migration->up(); // a second run changes nothing

        $types = DocumentType::count();
        $this->assertGreaterThan(0, $types);
        $this->assertSame($types * 2, DB::table('branch_document_type')->count());
        $this->assertSame($types, DB::table('branch_document_type')->where('branch_id', $swim->id)->count());
        $this->assertEqualsCanonicalizing(
            DocumentType::pluck('id')->all(),
            DocumentType::idsByBranch()[$foot->id],
        );
    }

    #[Test]
    public function a_new_branch_starts_with_no_documents(): void
    {
        $branch = Branch::create(['name' => 'Judo', 'code' => 'JU']);

        $this->assertSame(0, $branch->documentTypes()->count());
        $this->assertArrayNotHasKey($branch->id, DocumentType::idsByBranch());
    }

    #[Test]
    public function deleting_a_branch_or_type_removes_its_ticks(): void
    {
        $branch = Branch::create(['name' => 'Judo', 'code' => 'JU']);
        $type = DocumentType::create(['code' => 'x', 'name' => 'X', 'validity' => 'none']);
        $type->branches()->attach($branch->id);

        $type->delete();

        $this->assertSame(0, DB::table('branch_document_type')->where('branch_id', $branch->id)->count());
    }

    private const COPIES = 'migrations/2026_11_07_100002_add_copies_received_to_player_documents.php';

    #[Test]
    public function received_rows_get_their_types_copies_and_exempt_rows_stay_blank(): void
    {
        $type = DocumentType::create(['code' => 'cp', 'name' => 'Copies', 'validity' => 'none', 'copies' => 3]);
        $player = \App\Models\Player::create(['membership_id' => 'CP-1', 'firstname' => 'A', 'lastname' => 'B']);
        $received = DB::table('player_documents')->insertGetId(['player_id' => $player->id, 'document_type_id' => $type->id, 'state' => 'received', 'received_at' => '2026-09-01']);
        $other = DocumentType::where('code', 'birth_certificate')->firstOrFail();
        $exempt = DB::table('player_documents')->insertGetId(['player_id' => $player->id, 'document_type_id' => $other->id, 'state' => 'exempt']);

        $migration = require database_path(self::COPIES);
        $migration->up();
        $migration->up();

        $this->assertSame(3, (int) DB::table('player_documents')->where('id', $received)->value('copies_received'));
        $this->assertNull(DB::table('player_documents')->where('id', $exempt)->value('copies_received'));
    }
}
