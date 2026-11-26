<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mail register (سجل البريد): outgoing and incoming letters, numbered
 * per direction and calendar year (2026/01, incoming shown with a mark).
 * Entries are never deleted — cancelled ones keep their number. The old
 * Access letters are imported with their text reference and no number.
 *
 * An entry made from a print points at its document through source_type /
 * source_id / source_key. source_lock repeats those three while the entry is
 * active and is cleared on cancel; its unique index allows one active entry
 * per document on SQLite and MySQL alike (a partial index "where active" is
 * not portable, and NULLs never collide in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_entries', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);
            // Calendar year of entry_date; null only on an imported row with neither a date nor a year in its reference.
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('status', 20)->default('active');
            // Null only on imported rows (half the old register has no date).
            $table->date('entry_date')->nullable();
            // Recipient (outgoing) or sender (incoming); required by the form, null only on imported rows.
            $table->string('correspondent', 200)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('subject', 255)->nullable();
            // Outgoing letter text printed on the letterhead (optional).
            $table->text('body')->nullable();
            // Incoming only.
            $table->string('sender_reference', 100)->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            // One scan per entry, on the private disk.
            $table->string('scan_path', 255)->nullable();
            $table->string('scan_name', 255)->nullable();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 120)->nullable();
            $table->string('source_lock', 191)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_reference', 50)->nullable();
            $table->string('legacy_source', 20)->nullable();
            // Bumped by every edit claim so the claim always changes a row (MySQL counts changed rows only).
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['direction', 'year', 'number']);
            $table->unique('source_lock');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_entries');
    }
};
