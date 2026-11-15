<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Data only: starters saved before the slot map (ST, CM, LW… slots) have no
 * position. The desktop app runs migrations on first launch and nobody runs
 * artisan there, so the one-off repair runs here. It only fills empty
 * positions and is safe to run again; nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('competitions:fix-lineup-positions');
    }

    public function down(): void
    {
        // Filled positions are correct data; nothing to roll back.
    }
};
