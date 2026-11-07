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
}
