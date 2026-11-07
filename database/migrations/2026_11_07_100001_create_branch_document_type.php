<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which document types each branch requires (owner, 2026-11-07: one shared list,
 * each branch ticks its own). Explicit only: a type applies to the ticked
 * branches and nothing else. Every existing branch gets every existing type, so
 * nothing changes on deploy day; a branch created later starts with none.
 *
 * The backfill runs only on an empty table, so a second run changes nothing.
 * Literal values, not the models: a migration must not move when the code does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_document_type')) {
            Schema::create('branch_document_type', function (Blueprint $table) {
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
                $table->unique(['branch_id', 'document_type_id']);
                $table->index('document_type_id');
            });
        }

        if (DB::table('branch_document_type')->doesntExist()) {
            DB::table('branch_document_type')->insertUsing(
                ['branch_id', 'document_type_id'],
                DB::table('branches')->crossJoin('document_types')->select('branches.id as branch_id', 'document_types.id as document_type_id'),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_document_type');
    }
};
