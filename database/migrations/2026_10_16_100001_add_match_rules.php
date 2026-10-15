<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (match day). Formats learn three rules (rolling substitutions, a
 * substitution limit, extra-time length), and every match gets its own copy
 * of the rules it is played under (`games.rules`), so editing a format later
 * never rewrites a played match. Existing matches are backfilled from their
 * format. The column stays nullable at the database level (sqlite cannot
 * tighten it in place); Game::creating always fills it.
 */
return new class extends Migration
{
    private const ROLLING = ['Futsal', 'Handball', 'Basketball', 'Basketball 3x3', 'Volleyball'];

    private const EXTRA_TIME = ['Football 11' => 30, 'Futsal' => 10, 'Handball' => 10, 'Basketball' => 5];

    public function up(): void
    {
        Schema::table('game_formats', function (Blueprint $table) {
            $table->boolean('rolling_subs')->default(false)->after('has_shootout');
            $table->unsignedTinyInteger('max_substitutions')->nullable()->after('max_called_up');
            $table->unsignedTinyInteger('extra_time_minutes')->default(0)->after('has_extra_time');
        });
        Schema::table('games', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('game_format_id');
        });

        DB::table('game_formats')->where('is_preset', true)->whereIn('name', self::ROLLING)->update(['rolling_subs' => true]);
        DB::table('game_formats')->where('is_preset', true)->where('name', 'Football 11')->update(['max_substitutions' => 5]);
        foreach (self::EXTRA_TIME as $name => $minutes) {
            DB::table('game_formats')->where('is_preset', true)->where('name', $name)->update(['extra_time_minutes' => $minutes]);
        }

        $this->backfill();
    }

    /** Fills `rules` for every match that has none, from its format. Public so a test can re-run it. */
    public function backfill(): void
    {
        $formats = DB::table('game_formats')->get()->keyBy('id');
        DB::table('games')->whereNull('rules')->orderBy('id')->get(['id', 'game_format_id'])
            ->each(function ($game) use ($formats) {
                $f = $formats->get($game->game_format_id);
                if ($f === null) {
                    return;
                }
                DB::table('games')->where('id', $game->id)->update(['rules' => json_encode([
                    'sport' => $f->sport,
                    'players_on_field' => (int) $f->players_on_field,
                    'max_bench' => (int) $f->max_bench,
                    'max_called_up' => (int) $f->max_called_up,
                    'max_substitutions' => $f->max_substitutions === null ? null : (int) $f->max_substitutions,
                    'periods_count' => (int) $f->periods_count,
                    'period_minutes' => (int) $f->period_minutes,
                    'period_label' => $f->period_label,
                    'scoring' => $f->scoring,
                    'sets_to_win' => $f->sets_to_win === null ? null : (int) $f->sets_to_win,
                    'has_extra_time' => (bool) $f->has_extra_time,
                    'extra_time_minutes' => (int) $f->extra_time_minutes,
                    'has_shootout' => (bool) $f->has_shootout,
                    'rolling_subs' => (bool) $f->rolling_subs,
                    'sanction_types' => json_decode((string) $f->sanction_types, true) ?: [],
                ])]);
            });
    }

    public function down(): void
    {
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('rules'));
        Schema::table('game_formats', function (Blueprint $table) {
            $table->dropColumn(['rolling_subs', 'max_substitutions', 'extra_time_minutes']);
        });
    }
};
