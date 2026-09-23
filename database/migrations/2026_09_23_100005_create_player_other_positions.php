<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The positions a player also covers.
     *
     * The main one stays on players.position_id: the stats chart, bulk edit,
     * the import and the member card all read that single column, and a player
     * has exactly one main position. This table holds only the extras.
     */
    public function up(): void
    {
        Schema::create('player_other_positions', function (Blueprint $table) {
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();

            $table->primary(['player_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_other_positions');
    }
};
