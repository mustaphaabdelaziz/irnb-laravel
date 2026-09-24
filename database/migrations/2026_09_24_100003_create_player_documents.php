<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's documents: one row per (player, type) — received or exempt,
     * with its dates — and any number of scanned files per row.
     *
     * The type FK restricts deletion (Settings refuses to delete a type in
     * use; this is the database backing that up). The player FK cascades,
     * although permanent deletion also removes the rows and the files itself.
     *
     * Re-runnable: each table is created only when absent, because SQLite
     * does not roll back DDL and the desktop build re-runs an unrecorded
     * migration on the next boot.
     */
    public function up(): void
    {
        if (! Schema::hasTable('player_documents')) {
            Schema::create('player_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('player_id')->constrained()->cascadeOnDelete();
                $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
                $table->string('state', 16);
                $table->date('received_at')->nullable();
                $table->date('valid_until')->nullable();
                $table->string('exempt_reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['player_id', 'document_type_id']);
                $table->index(['document_type_id', 'state']);
            });
        }

        if (! Schema::hasTable('player_document_files')) {
            Schema::create('player_document_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('player_document_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name');
                $table->string('mime', 100);
                $table->unsignedBigInteger('size');
                $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('player_document_files');
        Schema::dropIfExists('player_documents');
    }
};
