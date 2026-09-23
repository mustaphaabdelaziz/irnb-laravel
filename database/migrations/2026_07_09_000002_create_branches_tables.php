<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_en')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_player', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->primary(['branch_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_player');
        Schema::dropIfExists('branches');
    }
};
