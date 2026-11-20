<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an edit changed on a transaction (old → new per field). Who and when
     * stay on the activity log row; the diff lives here so it is deleted with
     * the transaction and the activity log keeps holding no free text.
     */
    public function up(): void
    {
        Schema::create('transaction_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_log_id')->nullable()->constrained('activity_logs')->nullOnDelete();
            $table->json('diff');
            $table->timestamp('created_at')->nullable();

            // SQLite does not index a foreign key column; the page and every
            // transaction delete look revisions up by it.
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_revisions');
    }
};
