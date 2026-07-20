<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branches attach to the LOT, not the catalog: a catalog entry is a type
     * and has no location, while a lot is a physical batch that sits
     * somewhere. This expresses both real cases with one table — 50 dossards
     * to football and 50 to basketball are two lots with one branch each,
     * while a shared ball machine is one lot tagged with two branches.
     *
     * An empty pivot means club-wide, not orphaned.
     */
    public function up(): void
    {
        Schema::create('branch_equipment_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'equipment_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_equipment_item');
    }
};
