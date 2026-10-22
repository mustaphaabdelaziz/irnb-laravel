<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Skill level was dropped from the player form and the import (owner decision). */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('skill_level');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedTinyInteger('skill_level')->nullable();
        });
    }
};
