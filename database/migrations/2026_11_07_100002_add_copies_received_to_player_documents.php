<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many copies of a document came in (owner, 2026-11-07). Fewer than the type
 * asks for = incomplete, still missing. Every received row gets its type's copies,
 * so no old receipt turns incomplete on deploy day. Exempt rows stay blank.
 * Idempotent: the backfill touches blank received rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('player_documents', 'copies_received')) {
            Schema::table('player_documents', fn (Blueprint $table) => $table->unsignedSmallInteger('copies_received')->nullable());
        }

        DB::table('player_documents')
            ->where('state', 'received')
            ->whereNull('copies_received')
            ->update(['copies_received' => DB::raw('(SELECT document_types.copies FROM document_types WHERE document_types.id = player_documents.document_type_id)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('player_documents', 'copies_received')) {
            Schema::table('player_documents', fn (Blueprint $table) => $table->dropColumn('copies_received'));
        }
    }
};
