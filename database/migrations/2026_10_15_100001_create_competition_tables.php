<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Competitions & matches, phase 1. Dates are 'Y-m-d' and times 'H:i'
     * strings, like attendance, so sqlite (desktop) and MySQL (web) compare
     * them the same. Seasons are `season_start_year` integers (App\Support\Season).
     */
    public function up(): void
    {
        Schema::create('game_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sport', 16);
            $table->string('name', 100);
            $table->string('name_ar', 100)->nullable();
            $table->string('name_fr', 100)->nullable();
            $table->string('name_en', 100)->nullable();
            $table->unsignedTinyInteger('players_on_field');
            $table->unsignedTinyInteger('max_bench');
            $table->unsignedTinyInteger('max_called_up');
            $table->unsignedTinyInteger('periods_count');
            $table->unsignedTinyInteger('period_minutes');
            $table->string('period_label', 16);
            $table->string('scoring', 8);
            $table->unsignedTinyInteger('sets_to_win')->nullable();
            $table->boolean('has_extra_time')->default(false);
            $table->boolean('has_shootout')->default(false);
            $table->json('sanction_types');
            $table->boolean('is_preset')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('formations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_format_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->json('slots');
            $table->timestamps();
            $table->unique(['game_format_id', 'name']);
        });

        // Positions become per sport: the same abbreviation (GK) may exist once per branch.
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['abbreviation']);
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'abbreviation']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('own_teams_only')->default(false)->after('is_system');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->foreignId('default_game_format_id')->constrained('game_formats')->restrictOnDelete();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('team_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('shirt_number')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'season_start_year', 'player_id']);
            $table->unique(['team_id', 'season_start_year', 'shirt_number']);
        });

        Schema::create('team_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();
            $table->unique(['team_id', 'season_start_year', 'user_id']);
        });

        Schema::create('opponents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('city', 100)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('logo_filename')->nullable();
            $table->timestamps();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('address')->nullable();
            $table->boolean('is_home')->default(false);
            $table->timestamps();
        });

        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->string('name', 150);
            $table->string('type', 16);
            $table->foreignId('game_format_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('red_ban_matches')->default(1);
            $table->unsignedTinyInteger('yellow_threshold')->nullable();
            $table->unsignedTinyInteger('yellow_ban_matches')->default(1);
            $table->boolean('archived')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'season_start_year']);
        });

        Schema::create('competition_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['competition_id', 'player_id']);
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->foreignId('game_format_id')->constrained()->restrictOnDelete();
            $table->foreignId('opponent_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side', 8);
            $table->string('round_label', 100)->nullable();
            $table->string('date', 10);
            $table->string('kickoff_time', 5)->nullable();
            $table->string('meeting_time', 5)->nullable();
            $table->string('meeting_place', 150)->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('our_score')->nullable();
            $table->unsignedSmallInteger('their_score')->nullable();
            $table->json('period_scores')->nullable();
            $table->boolean('extra_time_played')->default(false);
            $table->unsignedTinyInteger('shootout_us')->nullable();
            $table->unsignedTinyInteger('shootout_them')->nullable();
            $table->foreignId('formation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('date');
            $table->index(['team_id', 'date']);
        });

        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->boolean('called_up')->default(false);
            $table->string('not_called_reason', 150)->nullable();
            $table->string('role', 8)->default('none');
            $table->unsignedTinyInteger('shirt_number')->nullable();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('slot')->nullable();
            $table->boolean('is_captain')->default(false);
            $table->boolean('is_goalkeeper')->default(false);
            $table->unsignedSmallInteger('minutes_played')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('coach_note')->nullable();
            $table->boolean('is_mvp')->default(false);
            $table->timestamps();
            $table->unique(['game_id', 'player_id']);
            $table->unique(['game_id', 'shirt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
        Schema::dropIfExists('games');
        Schema::dropIfExists('competition_players');
        Schema::dropIfExists('competitions');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('opponents');
        Schema::dropIfExists('team_coaches');
        Schema::dropIfExists('team_players');
        Schema::dropIfExists('teams');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('own_teams_only'));
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'name']);
            $table->dropUnique(['branch_id', 'abbreviation']);
            $table->dropConstrainedForeignId('branch_id');
            $table->unique('name');
            $table->unique('abbreviation');
        });
        Schema::dropIfExists('formations');
        Schema::dropIfExists('game_formats');
    }
};
