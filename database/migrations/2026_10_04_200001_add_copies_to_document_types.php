<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many copies of the document the player must hand in (Photo ×4, ...).
     * Information only (owner decision): shown in Settings and on the player's
     * checklist, never tracked per record. Every existing type starts at 1.
     *
     * Guarded so a second run after a failed SQLite migration is harmless.
     */
    public function up(): void
    {
        if (Schema::hasColumn('document_types', 'copies')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('copies')->default(1)->after('max_age');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_types', 'copies')) {
            Schema::table('document_types', function (Blueprint $table) {
                $table->dropColumn('copies');
            });
        }
    }
};
