<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\DocumentType;

/**
 * Types and branches created inside a test are not linked: the migration's
 * backfill ran before they existed. Call this after creating them when a test
 * means "every branch requires every type" (the behaviour before 2026-11-07).
 */
trait DocumentBranchFixtures
{
    private function requireDocumentsEverywhere(): void
    {
        $branchIds = Branch::pluck('id')->all();

        DocumentType::all()->each(fn (DocumentType $type) => $type->branches()->syncWithoutDetaching($branchIds));
    }
}
