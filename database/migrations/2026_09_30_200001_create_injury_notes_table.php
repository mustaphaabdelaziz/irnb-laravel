<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Details staff add to an injury spell. Spells themselves are built from
     * the marks (InjurySpells); a detail is keyed by its player and the
     * spell's start date ('Y-m-d', like every attendance date). A new table
     * only, guarded, so a second run (every desktop boot) does nothing and
     * no existing table is touched.
     */
    public function up(): void
    {
        if (Schema::hasTable('injury_notes')) {
            return;
        }

        Schema::create('injury_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('start_date', 10);
            $table->string('body_part', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('returned_on', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['player_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('injury_notes');
    }
};
