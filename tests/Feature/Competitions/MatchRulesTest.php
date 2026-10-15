<?php

namespace Tests\Feature\Competitions;

use App\Models\GameFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchRulesTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_new_match_copies_its_formats_rules_and_later_format_edits_leave_it_alone(): void
    {
        $team = $this->team();
        $team->defaultFormat->update(['period_minutes' => 30, 'max_substitutions' => 3, 'extra_time_minutes' => 20]);
        $game = $this->game($team);

        $team->defaultFormat->update(['period_minutes' => 40, 'max_called_up' => 5]);
        $game->refresh();

        $this->assertSame(30, $game->rule('period_minutes'));
        $this->assertSame(20, $game->rule('max_called_up'));
        $this->assertSame(3, $game->rule('max_substitutions'));
        $this->assertSame('football', $game->rule('sport'));
        $this->assertSame(60, $game->matchLength());
        $this->assertSame(80, $game->matchLength(true));
        $this->assertTrue($game->hasGoalkeeper());
    }

    #[Test]
    public function the_migration_backfills_rules_for_existing_matches(): void
    {
        $game = $this->game($this->team());
        DB::table('games')->where('id', $game->id)->update(['rules' => null]);

        (require database_path('migrations/2026_10_16_100001_add_match_rules.php'))->backfill();

        $this->assertSame(11, $game->fresh()->rule('players_on_field'));
    }

    #[Test]
    public function presets_know_rolling_substitutions_and_extra_time(): void
    {
        $this->assertTrue((bool) GameFormat::where('name', 'Handball')->value('rolling_subs'));
        $this->assertFalse((bool) GameFormat::where('name', 'Football 11')->value('rolling_subs'));
        $this->assertSame(5, GameFormat::where('name', 'Football 11')->value('max_substitutions'));
        $this->assertSame(30, GameFormat::where('name', 'Football 11')->value('extra_time_minutes'));
    }

    #[Test]
    public function the_call_up_limit_comes_from_the_matchs_rules(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b]);
        $game = $this->game($team);
        $game->update(['rules' => ['max_called_up' => 1] + $game->rules]);
        $team->defaultFormat->update(['max_called_up' => 30]);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true], ['player_id' => $b->id, 'called_up' => true],
        ]])->assertSessionHasErrors('players');
    }

    #[Test]
    public function the_format_page_saves_the_new_rule_fields(): void
    {
        $format = $this->format();
        $this->actingAs($this->admin())->put(route('game-formats.update', $format), [
            'branch_id' => $format->branch_id, 'sport' => 'football', 'name' => 'Test 11',
            'players_on_field' => 11, 'max_bench' => 9, 'max_called_up' => 20,
            'periods_count' => 2, 'period_minutes' => 45, 'period_label' => 'half', 'scoring' => 'goals',
            'has_extra_time' => true, 'extra_time_minutes' => 30, 'max_substitutions' => 5, 'rolling_subs' => false,
            'has_shootout' => true, 'active' => true, 'sanction_types' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(30, $format->fresh()->extra_time_minutes);
        $this->assertSame(5, $format->fresh()->max_substitutions);
    }
}
