<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match bans. "auto" rows are rebuilt from a competition's match reports
 * (SuspensionEngine); "manual" rows are federation or club decisions. A ban is
 * either N matches (served by the team's played matches) or a date range.
 * competition_id null = all competitions (manual only); team_id is the team
 * context used to authorize it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 8);
            $table->string('kind', 8);
            $table->unsignedTinyInteger('matches_count')->nullable();
            $table->string('starts_on', 10)->nullable();
            $table->string('ends_on', 10)->nullable();
            $table->string('cause', 8)->nullable();
            $table->foreignId('game_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['competition_id', 'source']);
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensions');
    }
};
