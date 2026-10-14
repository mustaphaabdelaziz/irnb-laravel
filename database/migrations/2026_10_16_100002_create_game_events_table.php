<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Match day: the report's timeline, and line-up / report fields per player. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('subtype', 32)->nullable();
            $table->unsignedTinyInteger('period')->nullable();
            $table->unsignedSmallInteger('minute')->nullable();
            $table->foreignId('player_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('related_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamps();
            $table->index(['game_id', 'minute']);
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->unsignedTinyInteger('pos_x')->nullable()->after('slot');
            $table->unsignedTinyInteger('pos_y')->nullable()->after('pos_x');
            $table->boolean('shirt_override')->default(false)->after('shirt_number');
            $table->unsignedTinyInteger('sets_played')->nullable()->after('minutes_played');
            $table->boolean('played')->default(false)->after('sets_played');
        });
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y', 'shirt_override', 'sets_played', 'played']);
        });
        Schema::dropIfExists('game_events');
    }
};
