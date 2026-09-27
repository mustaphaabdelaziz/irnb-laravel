<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skill level is optional on the player form; the NOT NULL column made
     * saving a player with no level fail with a 500.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedTinyInteger('skill_level')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('players')->whereNull('skill_level')->update(['skill_level' => 5]);

        Schema::table('players', function (Blueprint $table) {
            $table->unsignedTinyInteger('skill_level')->default(5)->change();
        });
    }
};
