<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The youngest age a document type applies to (owner, 2026-11-20): 15 means
     * the document is asked from the 15th birthday on. Null = from birth. It
     * pairs with `max_age`, so a type can be asked below an age, above one, or
     * between two. Every existing type starts without a minimum.
     *
     * Guarded so a second run after a failed SQLite migration is harmless.
     */
    public function up(): void
    {
        if (Schema::hasColumn('document_types', 'min_age')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_age')->nullable()->after('validity');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_types', 'min_age')) {
            Schema::table('document_types', function (Blueprint $table) {
                $table->dropColumn('min_age');
            });
        }
    }
};
